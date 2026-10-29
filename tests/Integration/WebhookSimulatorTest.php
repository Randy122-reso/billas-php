<?php

declare(strict_types=1);

namespace Billas\Tests\Integration;

use Billas\Domain\CustomerPaymentAccountService;
use Billas\Domain\WebhookService;
use Billas\Support\Settings;
use Billas\Support\Time;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

/**
 * Fitur F (v4.2) — domain-level simulator behaviour.
 */
final class WebhookSimulatorTest extends WebhookSimulatorTestCase
{
    public function testSignTripayPaysWebPortalOrderAndRecordsMaskedRun(): void
    {
        $packageId = $this->createPackage();
        $order = $this->createOrder($packageId, [
            'gateway' => 'tripay',
            'amount' => 10000,
            'source' => 'web_portal',
        ]);

        $result = $this->simulator()->simulate(
            'order',
            (string) $order['id'],
            'tripay',
            'PAID',
            null,
            'sign',
            1
        );

        self::assertSame('processed', $result['status']);
        self::assertGreaterThan(0, (int) $result['voucher_id']);
        self::assertSame('paid', $this->scalar('SELECT status FROM orders WHERE id = ?', [$order['id']]));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM vouchers WHERE order_id = ?', [$order['id']]));
        self::assertSame('web_portal', $this->scalar('SELECT source FROM vouchers WHERE order_id = ?', [$order['id']]));

        $run = $this->simulator()->findRun((int) $result['run_id']);
        self::assertNotNull($run);
        self::assertSame('sign', $run['mode']);
        self::assertSame(200, (int) $run['response_status']);
        self::assertSame('processed', $run['response']['status'] ?? null);
        self::assertSame('***MASKED***', $run['request']['headers']['X-Callback-Signature']);
        self::assertStringNotContainsString('test-tripay-private-key', (string) json_encode($run));

        self::assertSame(1, (int) $this->scalar(
            "SELECT COUNT(*) FROM audit_trail WHERE action = 'simulator.dispatch' AND entity_id = ?",
            [(string) $result['run_id']]
        ));
    }

    public function testSignMidtransPaysInvoiceAndCreatesSyntheticTransaction(): void
    {
        $customerId = $this->createCustomer(['status' => 'active']);
        $periodEnd = Time::addDays(Time::today(), 20);
        $invoiceId = $this->createInvoice($customerId, [
            'period_end' => $periodEnd,
            'amount' => 150000,
        ]);

        $result = $this->simulator()->simulate(
            'invoice',
            (string) $invoiceId,
            'midtrans',
            'PAID',
            null,
            'sign',
            1
        );

        self::assertSame('processed', $result['status']);
        self::assertSame('paid', $this->scalar('SELECT status FROM invoices WHERE id = ?', [$invoiceId]));
        self::assertSame($periodEnd, $this->scalar('SELECT paid_until FROM customers WHERE id = ?', [$customerId]));
        self::assertSame($periodEnd, $this->scalar('SELECT service_guaranteed_until FROM customers WHERE id = ?', [$customerId]));

        $transaction = $this->db()->query(
            'SELECT * FROM payment_transactions WHERE invoice_id = ' . $invoiceId
        )->fetch();
        self::assertNotFalse($transaction);
        self::assertSame('paid', $transaction['status']);
        self::assertSame(150000, (int) $transaction['amount']);
        self::assertTrue((bool) (json_decode((string) $transaction['payload'], true)['simulated'] ?? false));
    }

    public function testInvoiceSimulationReusesExistingPendingTransaction(): void
    {
        $customerId = $this->createCustomer(['status' => 'active']);
        $invoiceId = $this->createInvoice($customerId, ['amount' => 150000]);
        $transactionId = $this->createPaymentTransaction($invoiceId, [
            'gateway' => 'midtrans',
            'merchant_ref' => 'INV-REUSE-001',
            'amount' => 150000,
        ]);

        $result = $this->simulator()->simulate(
            'invoice',
            (string) $invoiceId,
            'midtrans',
            'PAID',
            null,
            'sign',
            1
        );

        self::assertSame('processed', $result['status']);
        self::assertSame(1, (int) $this->scalar(
            'SELECT COUNT(*) FROM payment_transactions WHERE invoice_id = ?',
            [$invoiceId]
        ));
        self::assertSame('paid', $this->scalar('SELECT status FROM payment_transactions WHERE id = ?', [$transactionId]));
        self::assertSame('paid', $this->scalar('SELECT status FROM invoices WHERE id = ?', [$invoiceId]));
    }

    public function testSignXenditPaysPersistentAccountInvoice(): void
    {
        $customerId = $this->createCustomer(['status' => 'active']);
        $periodEnd = Time::addDays(Time::today(), 15);
        $invoiceId = $this->createInvoice($customerId, [
            'period_end' => $periodEnd,
            'amount' => 150000,
        ]);

        /** @var CustomerPaymentAccountService $accounts */
        $accounts = $this->service(CustomerPaymentAccountService::class);
        $account = $accounts->getOrCreateQris($customerId, 'xendit');

        $result = $this->simulator()->simulate(
            'persistent_account',
            (string) $account['id'],
            'xendit',
            'PAID',
            null,
            'sign',
            1
        );

        self::assertSame('processed', $result['status']);
        self::assertSame('paid', $this->scalar('SELECT status FROM invoices WHERE id = ?', [$invoiceId]));
        self::assertSame((int) $account['id'], (int) $this->scalar(
            'SELECT paid_via_account_id FROM invoices WHERE id = ?',
            [$invoiceId]
        ));
        self::assertSame($periodEnd, $this->scalar('SELECT paid_until FROM customers WHERE id = ?', [$customerId]));
        self::assertSame(1, (int) $this->scalar(
            'SELECT active FROM customer_payment_accounts WHERE id = ?',
            [$account['id']]
        ));
    }

    public function testSignDuitkuPaysOrderUsingFormEncodedSignature(): void
    {
        $packageId = $this->createPackage();
        $order = $this->createOrder($packageId, [
            'gateway' => 'duitku',
            'amount' => 10000,
            'source' => 'web_portal',
        ]);

        $result = $this->simulator()->simulate(
            'order',
            (string) $order['id'],
            'duitku',
            'PAID',
            null,
            'sign',
            1
        );

        self::assertSame('processed', $result['status']);
        self::assertSame('paid', $this->scalar('SELECT status FROM orders WHERE id = ?', [$order['id']]));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM vouchers WHERE order_id = ?', [$order['id']]));

        $run = $this->simulator()->findRun((int) $result['run_id']);
        self::assertNotNull($run);
        $request = $run['request'];
        parse_str((string) $request['body'], $body);
        self::assertSame('***MASKED***', $body['signature']);
        self::assertSame((string) $order['merchant_ref'], $body['merchantOrderId']);
    }

    public function testDuplicateDispatchKeepsSingleVoucher(): void
    {
        $packageId = $this->createPackage();
        $order = $this->createOrder($packageId, ['gateway' => 'tripay', 'amount' => 10000]);
        $simulator = $this->simulator();
        $built = $simulator->build('order', (string) $order['id'], 'tripay', 'PAID');

        $first = $simulator->dispatch($built, 'sign', 1);
        $second = $simulator->dispatch($built, 'sign', 1);

        self::assertSame('processed', $first['status']);
        self::assertSame('duplicate', $second['status']);
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM vouchers WHERE order_id = ?', [$order['id']]));
        self::assertSame(1, (int) $this->scalar(
            'SELECT COUNT(*) FROM webhook_events WHERE merchant_ref = ?',
            [$order['merchant_ref']]
        ));
        self::assertSame(2, (int) $this->scalar('SELECT COUNT(*) FROM simulator_runs'));
    }

    public function testRealExpiredWebhookIsIgnoredButSimulatedExpiredUpdatesOrder(): void
    {
        $packageId = $this->createPackage();
        $order = $this->createOrder($packageId, ['gateway' => 'tripay', 'amount' => 10000]);
        $simulator = $this->simulator();
        $built = $simulator->build('order', (string) $order['id'], 'tripay', 'EXPIRED');

        /** @var WebhookService $webhooks */
        $webhooks = $this->service(WebhookService::class);
        $real = $webhooks->handle(
            'tripay',
            $built['headers'],
            (string) json_encode($built['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            false
        );

        self::assertSame('ignored', $real['status']);
        self::assertSame('pending', $this->scalar('SELECT status FROM orders WHERE id = ?', [$order['id']]));

        $result = $simulator->simulate(
            'order',
            (string) $order['id'],
            'tripay',
            'EXPIRED',
            null,
            'sign',
            1
        );

        self::assertSame('processed', $result['status']);
        self::assertSame('expired', $this->scalar('SELECT status FROM orders WHERE id = ?', [$order['id']]));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM vouchers WHERE order_id = ?', [$order['id']]));
        self::assertSame(1, (int) $this->scalar(
            "SELECT COUNT(*) FROM audit_trail WHERE action = 'order.expired' AND entity_id = ?",
            [(string) $order['id']]
        ));
    }

    public function testSimulatedFailedEventUpdatesPendingInvoiceTransaction(): void
    {
        $customerId = $this->createCustomer(['status' => 'active']);
        $invoiceId = $this->createInvoice($customerId, ['amount' => 150000]);
        $transactionId = $this->createPaymentTransaction($invoiceId, [
            'gateway' => 'duitku',
            'merchant_ref' => 'INV-FAILED-001',
            'amount' => 150000,
        ]);

        $result = $this->simulator()->simulate(
            'invoice',
            (string) $invoiceId,
            'duitku',
            'FAILED',
            null,
            'sign',
            1
        );

        self::assertSame('processed', $result['status']);
        self::assertSame('failed', $this->scalar('SELECT status FROM payment_transactions WHERE id = ?', [$transactionId]));
        self::assertSame('unpaid', $this->scalar('SELECT status FROM invoices WHERE id = ?', [$invoiceId]));
        self::assertSame(1, (int) $this->scalar(
            "SELECT COUNT(*) FROM audit_trail WHERE action = 'payment.failed' AND entity_id = ?",
            [(string) $transactionId]
        ));
    }

    public function testPersistentAccountExpiredEventIsIgnored(): void
    {
        $customerId = $this->createCustomer(['status' => 'active']);
        $invoiceId = $this->createInvoice($customerId, ['amount' => 150000]);

        /** @var CustomerPaymentAccountService $accounts */
        $accounts = $this->service(CustomerPaymentAccountService::class);
        $account = $accounts->getOrCreateQris($customerId, 'xendit');

        $result = $this->simulator()->simulate(
            'persistent_account',
            (string) $account['id'],
            'xendit',
            'EXPIRED',
            null,
            'sign',
            1
        );

        self::assertSame('ignored', $result['status']);
        self::assertStringContainsString('Akun persisten', (string) $result['message']);
        self::assertSame('unpaid', $this->scalar('SELECT status FROM invoices WHERE id = ?', [$invoiceId]));
        self::assertSame(1, (int) $this->scalar(
            'SELECT active FROM customer_payment_accounts WHERE id = ?',
            [$account['id']]
        ));
    }

    public function testAmountOverrideProducesUnmatchedEventAndAudit(): void
    {
        $packageId = $this->createPackage();
        $order = $this->createOrder($packageId, ['gateway' => 'tripay', 'amount' => 10000]);

        $result = $this->simulator()->simulate(
            'order',
            (string) $order['id'],
            'tripay',
            'PAID',
            9000,
            'sign',
            1
        );

        self::assertSame('unmatched', $result['status']);
        self::assertSame('pending', $this->scalar('SELECT status FROM orders WHERE id = ?', [$order['id']]));
        self::assertSame(1, (int) $this->scalar(
            "SELECT COUNT(*) FROM audit_trail WHERE action = 'webhook.amount_mismatch' AND entity_id = ?",
            [(string) $order['id']]
        ));

        $meta = json_decode((string) $this->scalar(
            "SELECT meta FROM audit_trail WHERE action = 'webhook.amount_mismatch' AND entity_id = ?",
            [(string) $order['id']]
        ), true);
        self::assertSame(10000, (int) $meta['expected']);
        self::assertSame(9000, (int) $meta['received']);

        $event = $this->db()->query(
            "SELECT status FROM webhook_events WHERE merchant_ref = " . $this->db()->quote((string) $order['merchant_ref'])
        )->fetch();
        self::assertSame('unmatched', $event['status']);

        $run = $this->simulator()->findRun((int) $result['run_id']);
        self::assertSame(200, (int) $run['response_status']);
        self::assertSame('unmatched', $run['response']['status'] ?? null);
    }

    public function testBypassQrisStaticPaysCaptiveOrderWithoutSignature(): void
    {
        $packageId = $this->createPackage();
        $order = $this->createOrder($packageId, [
            'gateway' => 'qris_static',
            'amount' => 10000,
            'source' => 'captive_portal',
        ]);

        $result = $this->simulator()->simulate(
            'order',
            (string) $order['id'],
            'qris_static',
            'PAID',
            null,
            'bypass',
            1
        );

        self::assertSame('processed', $result['status']);
        self::assertSame('paid', $this->scalar('SELECT status FROM orders WHERE id = ?', [$order['id']]));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM vouchers WHERE order_id = ?', [$order['id']]));
        self::assertSame('captive_portal', $this->scalar('SELECT source FROM vouchers WHERE order_id = ?', [$order['id']]));

        $run = $this->simulator()->findRun((int) $result['run_id']);
        self::assertNotNull($run);
        self::assertSame('bypass', $run['mode']);
        self::assertSame('1', $run['request']['headers']['X-Billas-Simulated']);
    }

    public function testQrisStaticSignModeIsRejected(): void
    {
        $packageId = $this->createPackage();
        $order = $this->createOrder($packageId, ['gateway' => 'qris_static', 'amount' => 10000]);

        try {
            $this->simulator()->simulate(
                'order',
                (string) $order['id'],
                'qris_static',
                'PAID',
                null,
                'sign'
            );
            self::fail('QRIS statis seharusnya menolak mode sign.');
        } catch (LogicException $e) {
            self::assertStringContainsString('QRIS statis', $e->getMessage());
        }

        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM vouchers'));
        self::assertSame('pending', $this->scalar('SELECT status FROM orders WHERE id = ?', [$order['id']]));
    }

    public function testDisabledFeatureBlocksSimulatorDispatch(): void
    {
        $packageId = $this->createPackage();
        $order = $this->createOrder($packageId, ['gateway' => 'tripay', 'amount' => 10000]);

        /** @var Settings $settings */
        $settings = $this->service(Settings::class);
        $settings->set('features.webhook_simulator', '0');

        try {
            $this->simulator()->simulate(
                'order',
                (string) $order['id'],
                'tripay',
                'PAID',
                null,
                'sign'
            );
            self::fail('Simulator seharusnya diblokir saat flag OFF.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Simulator dimatikan', $e->getMessage());
        }

        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM simulator_runs'));
        self::assertSame('pending', $this->scalar('SELECT status FROM orders WHERE id = ?', [$order['id']]));
    }

    public function testSimulatedWebhookRequiresSimulationHeader(): void
    {
        $packageId = $this->createPackage();
        $order = $this->createOrder($packageId, ['gateway' => 'tripay', 'amount' => 10000]);
        $built = $this->simulator()->build('order', (string) $order['id'], 'tripay', 'PAID');

        /** @var WebhookService $webhooks */
        $webhooks = $this->service(WebhookService::class);

        try {
            $webhooks->handle(
                'tripay',
                $built['headers'],
                (string) json_encode($built['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                true
            );
            self::fail('Simulated webhook tanpa header seharusnya ditolak.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('X-Billas-Simulated', $e->getMessage());
        }

        self::assertSame('pending', $this->scalar('SELECT status FROM orders WHERE id = ?', [$order['id']]));
    }

    public function testFailedBuildIsRecordedForAudit(): void
    {
        try {
            $this->simulator()->simulate(
                'order',
                'order-tidak-ada',
                'tripay',
                'PAID',
                null,
                'sign',
                1
            );
            self::fail('Target yang tidak ada seharusnya gagal.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('tidak ditemukan', $e->getMessage());
        }

        $runs = $this->simulator()->recentRuns(1);
        self::assertCount(1, $runs);
        $run = $runs[0];
        self::assertSame('order', $run['target_type']);
        self::assertSame('order-tidak-ada', $run['target_id']);
        self::assertSame('tripay', $run['provider']);
        self::assertSame('PAID', $run['event']);
        self::assertSame('sign', $run['mode']);
        self::assertSame(422, (int) $run['response_status']);
        self::assertNull($run['request']);
        self::assertSame('error', $run['response']['status'] ?? null);
        self::assertSame(1, (int) $this->scalar(
            "SELECT COUNT(*) FROM audit_trail WHERE action = 'simulator.dispatch' AND entity_id = ?",
            [(string) $run['id']]
        ));
    }

    public function testBuildRejectsInvalidTargetTypeAndUnknownProvider(): void
    {
        $packageId = $this->createPackage();
        $order = $this->createOrder($packageId);

        try {
            $this->simulator()->build('bogus', (string) $order['id'], 'tripay', 'PAID');
            self::fail('target_type tidak valid seharusnya ditolak.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('target_type', $e->getMessage());
        }

        try {
            $this->simulator()->build('order', (string) $order['id'], 'bogus', 'PAID');
            self::fail('Provider tidak dikenal seharusnya ditolak.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('Provider', $e->getMessage());
        }
    }

    public function testStatusAndOptionsExposeSimulatorCapabilities(): void
    {
        $simulator = $this->simulator();
        $status = $simulator->status();

        self::assertTrue($status['enabled']);
        self::assertSame('sign', $status['mode']);
        self::assertFalse($status['allow_in_production']);
        self::assertFalse($status['production']);
        self::assertContains('tripay', $status['signing_providers']);
        self::assertContains('qris_static', $status['configured_providers']);
        self::assertNotContains('qris_static', $status['signing_providers']);

        $options = $simulator->options();
        self::assertSame(['PAID', 'EXPIRED', 'FAILED'], $options['events']);
        self::assertSame(['sign', 'bypass'], $options['modes']);
        self::assertCount(5, $options['providers']);
        self::assertArrayHasKey('orders', $options);
        self::assertArrayHasKey('invoices', $options);
        self::assertArrayHasKey('accounts', $options);
    }
}
