<?php

declare(strict_types=1);

namespace Billas\Tests\Integration;

use Billas\Domain\CustomerPaymentAccountService;
use Billas\Domain\QrisUtil;
use Billas\Tests\DatabaseTestCase;
use RuntimeException;

/**
 * Feature D — unique QRIS account and service guarantee.
 */
final class FeatureDTest extends DatabaseTestCase
{
    public function testGetOrCreateQrisIsIdempotent(): void
    {
        $customerId = $this->createCustomer();

        /** @var CustomerPaymentAccountService $accounts */
        $accounts = $this->service(CustomerPaymentAccountService::class);
        $first = $accounts->getOrCreateQris($customerId);
        $second = $accounts->getOrCreateQris($customerId);

        self::assertSame((int) $first['id'], (int) $second['id']);
        self::assertSame(1, (int) $this->scalar(
            'SELECT COUNT(*) FROM customer_payment_accounts WHERE customer_id = ?',
            [$customerId]
        ));
        self::assertSame('qris_static', $first['gateway']);
        self::assertSame('qris', $first['account_type']);
        self::assertNotEmpty($first['external_ref']);
        self::assertTrue(QrisUtil::isValid((string) $first['qr_string']));
    }

    public function testPersistentAccountRejectsProviderThatDoesNotSupportIt(): void
    {
        $customerId = $this->createCustomer();

        /** @var CustomerPaymentAccountService $accounts */
        $accounts = $this->service(CustomerPaymentAccountService::class);

        $this->expectException(RuntimeException::class);
        $accounts->getOrCreate($customerId, 'tripay', 'va');
    }

    public function testMarkPaidExtendsGuaranteeAndPaymentDate(): void
    {
        $customerId = $this->createCustomer(['status' => 'active']);
        $periodEnd = \Billas\Support\Time::addDays(\Billas\Support\Time::today(), 20);
        $invoiceId = $this->createInvoice($customerId, ['period_end' => $periodEnd]);

        $invoice = $this->service(\Billas\Domain\InvoiceService::class)->markPaid($invoiceId);

        self::assertSame('paid', $invoice['status'] ?? null);
        self::assertSame($periodEnd, $this->scalar('SELECT service_guaranteed_until FROM customers WHERE id = ?', [$customerId]));
        self::assertSame($periodEnd, $this->scalar('SELECT paid_until FROM customers WHERE id = ?', [$customerId]));
    }

    public function testGuaranteeIsMonotonicAndNeverDecreases(): void
    {
        $customerId = $this->createCustomer(['status' => 'active']);
        $laterPeriodEnd = \Billas\Support\Time::addDays(\Billas\Support\Time::today(), 40);
        $earlierPeriodEnd = \Billas\Support\Time::addDays(\Billas\Support\Time::today(), 5);

        /** @var \Billas\Domain\InvoiceService $invoices */
        $invoices = $this->service(\Billas\Domain\InvoiceService::class);
        $invoices->markPaid($this->createInvoice($customerId, ['period_end' => $laterPeriodEnd]));
        $invoices->markPaid($this->createInvoice($customerId, ['period_end' => $earlierPeriodEnd]));

        self::assertSame(
            $laterPeriodEnd,
            $this->scalar('SELECT service_guaranteed_until FROM customers WHERE id = ?', [$customerId])
        );
        self::assertSame(
            $laterPeriodEnd,
            $this->scalar('SELECT paid_until FROM customers WHERE id = ?', [$customerId])
        );
    }

    public function testIsolirSkipsCustomerInsideGuaranteeWindow(): void
    {
        $customerId = $this->createCustomer([
            'status' => 'active',
            'paid_until' => \Billas\Support\Time::addDays(\Billas\Support\Time::today(), -10),
            'service_guaranteed_until' => \Billas\Support\Time::addDays(\Billas\Support\Time::today(), 5),
        ]);
        $this->createInvoice($customerId, [
            'due_date' => \Billas\Support\Time::addDays(\Billas\Support\Time::today(), -10),
        ]);

        $result = $this->service(\Billas\Domain\CustomerService::class)->isolirDue(3);

        self::assertNotContains($customerId, $result['isolated']);
        self::assertGreaterThanOrEqual(1, $result['skipped_guaranteed']);
        self::assertSame('active', $this->scalar('SELECT status FROM customers WHERE id = ?', [$customerId]));
    }

    public function testIsolirRunsAfterGuaranteeHasPassed(): void
    {
        $customerId = $this->createCustomer([
            'status' => 'active',
            'paid_until' => \Billas\Support\Time::addDays(\Billas\Support\Time::today(), -10),
            'service_guaranteed_until' => \Billas\Support\Time::addDays(\Billas\Support\Time::today(), -1),
        ]);
        $this->createInvoice($customerId, [
            'due_date' => \Billas\Support\Time::addDays(\Billas\Support\Time::today(), -10),
        ]);

        $result = $this->service(\Billas\Domain\CustomerService::class)->isolirDue(3);

        self::assertContains($customerId, $result['isolated']);
        self::assertSame('isolir', $this->scalar('SELECT status FROM customers WHERE id = ?', [$customerId]));
    }

    public function testIsolirFallsBackToV3LogicWhenGuaranteeIsNull(): void
    {
        $customerId = $this->createCustomer([
            'status' => 'active',
            'paid_until' => null,
            'service_guaranteed_until' => null,
        ]);
        $this->createInvoice($customerId, [
            'due_date' => \Billas\Support\Time::addDays(\Billas\Support\Time::today(), -10),
        ]);

        $result = $this->service(\Billas\Domain\CustomerService::class)->isolirDue(3);

        self::assertContains($customerId, $result['isolated']);
    }

    public function testPersistentPaymentPaysOpenInvoiceAndReactivatesCustomer(): void
    {
        $customerId = $this->createCustomer([
            'status' => 'isolir',
            'paid_until' => \Billas\Support\Time::addDays(\Billas\Support\Time::today(), -15),
            'service_guaranteed_until' => null,
        ]);
        $periodEnd = \Billas\Support\Time::addDays(\Billas\Support\Time::today(), 10);
        $invoiceId = $this->createInvoice($customerId, [
            'period_end' => $periodEnd,
            'amount' => 150000,
        ]);

        /** @var \Billas\Domain\InvoiceService $invoices */
        $invoices = $this->service(\Billas\Domain\InvoiceService::class);
        $result = $invoices->applyPersistentPayment($customerId, 150000, null, 'QRISSTATIC-TEST');

        self::assertFalse($result['created_prepaid']);
        self::assertSame('paid', $this->scalar('SELECT status FROM invoices WHERE id = ?', [$invoiceId]));
        self::assertSame('active', $this->scalar('SELECT status FROM customers WHERE id = ?', [$customerId]));
        self::assertSame($periodEnd, $this->scalar('SELECT service_guaranteed_until FROM customers WHERE id = ?', [$customerId]));
        self::assertGreaterThanOrEqual(1, (int) $this->scalar(
            "SELECT COUNT(*) FROM retry_queue WHERE task_type = 'pppoe_enable' AND status = 'pending'"
        ));
        self::assertGreaterThanOrEqual(1, (int) $this->scalar(
            "SELECT COUNT(*) FROM retry_queue WHERE task_type = 'pppoe_kick' AND status = 'pending'"
        ));
    }
}
