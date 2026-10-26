<?php

declare(strict_types=1);

namespace Billas\Tests\Integration;

use Billas\Domain\CustomerPaymentAccountService;
use Billas\Domain\ReconciliationService;
use Billas\Support\Time;
use Billas\Tests\DatabaseTestCase;
use RuntimeException;

/**
 * Manual reconciliation of a persistent QRIS account: there is no order or
 * invoice transaction row, so the operator supplies the bank-statement amount.
 */
final class ReconciliationTest extends DatabaseTestCase
{
    public function testConfirmAccountAppliesInvoicePaymentAndIsIdempotent(): void
    {
        $customerId = $this->createCustomer([
            'status' => 'isolir',
            'paid_until' => Time::addDays(Time::today(), -15),
            'service_guaranteed_until' => null,
        ]);
        $periodEnd = Time::addDays(Time::today(), 10);
        $invoiceId = $this->createInvoice($customerId, [
            'period_end' => $periodEnd,
            'amount' => 150000,
        ]);

        /** @var CustomerPaymentAccountService $accounts */
        $accounts = $this->service(CustomerPaymentAccountService::class);
        $account = $accounts->getOrCreateQris($customerId);
        $externalRef = (string) $account['external_ref'];

        /** @var ReconciliationService $reconciliation */
        $reconciliation = $this->service(ReconciliationService::class);
        $result = $reconciliation->confirmAccount($externalRef, 150000, 'tester', 'MUT-2026-0001');

        self::assertSame('processed', $result['status']);
        self::assertSame('paid', $this->scalar('SELECT status FROM invoices WHERE id = ?', [$invoiceId]));
        self::assertSame('active', $this->scalar('SELECT status FROM customers WHERE id = ?', [$customerId]));
        self::assertSame(
            $periodEnd,
            $this->scalar('SELECT service_guaranteed_until FROM customers WHERE id = ?', [$customerId])
        );
        self::assertSame(1, (int) $this->scalar(
            "SELECT COUNT(*) FROM webhook_events
             WHERE provider = 'manual' AND merchant_ref = ? AND status = 'processed'",
            [$externalRef]
        ));
        self::assertGreaterThanOrEqual(1, (int) $this->scalar(
            "SELECT COUNT(*) FROM audit_trail WHERE action = 'qris_unique.manual_confirm'"
        ));

        // The same bank mutation must not pay (or create) another invoice.
        $duplicate = $reconciliation->confirmAccount($externalRef, 150000, 'tester', 'MUT-2026-0001');
        self::assertSame('duplicate', $duplicate['status']);
        self::assertSame(1, (int) $this->scalar(
            'SELECT COUNT(*) FROM invoices WHERE customer_id = ?',
            [$customerId]
        ));
    }

    public function testConfirmAccountRequiresARealAmount(): void
    {
        $customerId = $this->createCustomer();
        $accounts = $this->service(CustomerPaymentAccountService::class);
        $account = $accounts->getOrCreateQris($customerId);

        $reconciliation = $this->service(ReconciliationService::class);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('minimal Rp1.000');
        $reconciliation->confirmAccount((string) $account['external_ref'], 999, 'tester');
    }

    public function testConfirmAccountRejectsUnknownReference(): void
    {
        $reconciliation = $this->service(ReconciliationService::class);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tidak ditemukan');
        $reconciliation->confirmAccount('QRIS-TIDAK-ADA', 10000, 'tester');
    }
}
