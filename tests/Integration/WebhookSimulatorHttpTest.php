<?php

declare(strict_types=1);

namespace Billas\Tests\Integration;

use Billas\Domain\CustomerPaymentAccountService;
use Billas\Support\Settings;
use Billas\Support\Time;

/**
 * Fitur F (v4.2) — HTTP route, middleware, CSRF, rate-limit and UI coverage.
 */
final class WebhookSimulatorHttpTest extends WebhookSimulatorTestCase
{
    public function testSimulatorRequiresAdminSession(): void
    {
        $response = $this->http('GET', '/admin/simulator');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/admin/login', $response->getHeaderLine('Location'));
    }

    public function testPostWithoutCsrfReturns419(): void
    {
        [$sessionId] = $this->adminSession();

        $response = $this->http('POST', '/admin/simulator/webhook', [
            'session' => $sessionId,
            'headers' => ['Accept' => 'application/json'],
            'body' => [
                'target_type' => 'order',
                'target_id' => 'missing',
                'provider' => 'tripay',
                'event' => 'PAID',
                'mode' => 'sign',
            ],
        ]);

        self::assertSame(419, $response->getStatusCode());
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM simulator_runs'));
    }

    public function testFlagOffReturns403(): void
    {
        [$sessionId] = $this->adminSession();

        /** @var Settings $settings */
        $settings = $this->service(Settings::class);
        $settings->set('features.webhook_simulator', '0');

        $response = $this->http('GET', '/admin/simulator', ['session' => $sessionId]);

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('Simulator dimatikan', (string) $response->getBody());
    }

    public function testSimulatorIndexRendersFormAndHistory(): void
    {
        [$sessionId] = $this->adminSession();

        $response = $this->http('GET', '/admin/simulator', ['session' => $sessionId]);
        $html = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Simulator Webhook', $html);
        self::assertStringContainsString('Kirim webhook sintetis', $html);
        self::assertStringContainsString('/admin/simulator/webhook', $html);
        self::assertStringContainsString('Riwayat 20 run terakhir', $html);
    }

    public function testRateLimitReturns429AfterTwentyRequests(): void
    {
        [$sessionId, $csrf] = $this->adminSession();
        $body = [
            'target_type' => 'order',
            'target_id' => 'missing-target',
            'provider' => 'tripay',
            'event' => 'PAID',
            'mode' => 'sign',
        ];

        for ($attempt = 1; $attempt <= 20; $attempt++) {
            $response = $this->http('POST', '/admin/simulator/webhook', [
                'session' => $sessionId,
                'headers' => [
                    'Accept' => 'application/json',
                    'X-CSRF-Token' => $csrf,
                ],
                'body' => $body,
            ]);
            self::assertSame(422, $response->getStatusCode(), 'request #' . $attempt);
        }

        $response = $this->http('POST', '/admin/simulator/webhook', [
            'session' => $sessionId,
            'headers' => [
                'Accept' => 'application/json',
                'X-CSRF-Token' => $csrf,
            ],
            'body' => $body,
        ]);

        self::assertSame(429, $response->getStatusCode());
        self::assertNotSame('', $response->getHeaderLine('Retry-After'));
        $json = $this->jsonResponse($response);
        self::assertFalse($json['ok']);
        self::assertStringContainsString('Terlalu banyak', (string) $json['error']);
    }

    public function testHttpSignOrderCreatesVoucher(): void
    {
        $packageId = $this->createPackage();
        $order = $this->createOrder($packageId, [
            'gateway' => 'tripay',
            'amount' => 10000,
            'source' => 'web_portal',
        ]);
        [$sessionId, $csrf] = $this->adminSession();

        $response = $this->http('POST', '/admin/simulator/webhook', [
            'session' => $sessionId,
            'headers' => [
                'Accept' => 'application/json',
                'X-CSRF-Token' => $csrf,
            ],
            'body' => [
                'target_type' => 'order',
                'target_id' => (string) $order['id'],
                'provider' => 'tripay',
                'event' => 'PAID',
                'mode' => 'sign',
            ],
        ]);

        $json = $this->jsonResponse($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($json['ok']);
        self::assertSame('processed', $json['status']);
        self::assertGreaterThan(0, (int) $json['voucher_id']);
        self::assertSame('paid', $this->scalar('SELECT status FROM orders WHERE id = ?', [$order['id']]));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM vouchers WHERE order_id = ?', [$order['id']]));
    }

    public function testHttpSignInvoiceExtendsPaidUntil(): void
    {
        $customerId = $this->createCustomer(['status' => 'active']);
        $periodEnd = Time::addDays(Time::today(), 20);
        $invoiceId = $this->createInvoice($customerId, [
            'period_end' => $periodEnd,
            'amount' => 150000,
        ]);
        [$sessionId, $csrf] = $this->adminSession();

        $response = $this->http('POST', '/admin/simulator/webhook', [
            'session' => $sessionId,
            'headers' => [
                'Accept' => 'application/json',
                'X-CSRF-Token' => $csrf,
            ],
            'body' => [
                'target_type' => 'invoice',
                'target_id' => (string) $invoiceId,
                'provider' => 'midtrans',
                'event' => 'PAID',
                'mode' => 'sign',
            ],
        ]);

        $json = $this->jsonResponse($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('processed', $json['status']);
        self::assertSame('paid', $this->scalar('SELECT status FROM invoices WHERE id = ?', [$invoiceId]));
        self::assertSame($periodEnd, $this->scalar('SELECT paid_until FROM customers WHERE id = ?', [$customerId]));
        self::assertSame($periodEnd, $this->scalar(
            'SELECT service_guaranteed_until FROM customers WHERE id = ?',
            [$customerId]
        ));
    }

    public function testHttpSignPersistentAccountPaysInvoice(): void
    {
        $customerId = $this->createCustomer(['status' => 'active']);
        $invoiceId = $this->createInvoice($customerId, ['amount' => 150000]);

        /** @var CustomerPaymentAccountService $accounts */
        $accounts = $this->service(CustomerPaymentAccountService::class);
        $account = $accounts->getOrCreateQris($customerId, 'xendit');
        [$sessionId, $csrf] = $this->adminSession();

        $response = $this->http('POST', '/admin/simulator/webhook', [
            'session' => $sessionId,
            'headers' => [
                'Accept' => 'application/json',
                'X-CSRF-Token' => $csrf,
            ],
            'body' => [
                'target_type' => 'persistent_account',
                'target_id' => (string) $account['id'],
                'provider' => 'xendit',
                'event' => 'PAID',
                'mode' => 'sign',
            ],
        ]);

        $json = $this->jsonResponse($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('processed', $json['status']);
        self::assertSame('paid', $this->scalar('SELECT status FROM invoices WHERE id = ?', [$invoiceId]));
        self::assertSame((int) $account['id'], (int) $this->scalar(
            'SELECT paid_via_account_id FROM invoices WHERE id = ?',
            [$invoiceId]
        ));
    }

    public function testHttpBypassQrisStaticPaysOrder(): void
    {
        $packageId = $this->createPackage();
        $order = $this->createOrder($packageId, [
            'gateway' => 'qris_static',
            'amount' => 10000,
        ]);
        [$sessionId, $csrf] = $this->adminSession();

        $response = $this->http('POST', '/admin/simulator/webhook', [
            'session' => $sessionId,
            'headers' => [
                'Accept' => 'application/json',
                'X-CSRF-Token' => $csrf,
            ],
            'body' => [
                'target_type' => 'order',
                'target_id' => (string) $order['id'],
                'provider' => 'qris_static',
                'event' => 'PAID',
                'mode' => 'bypass',
            ],
        ]);

        $json = $this->jsonResponse($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('processed', $json['status']);
        self::assertSame('paid', $this->scalar('SELECT status FROM orders WHERE id = ?', [$order['id']]));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM vouchers WHERE order_id = ?', [$order['id']]));
    }

    public function testHttpQrisStaticSignModeReturns422(): void
    {
        $packageId = $this->createPackage();
        $order = $this->createOrder($packageId, ['gateway' => 'qris_static', 'amount' => 10000]);
        [$sessionId, $csrf] = $this->adminSession();

        $response = $this->http('POST', '/admin/simulator/webhook', [
            'session' => $sessionId,
            'headers' => [
                'Accept' => 'application/json',
                'X-CSRF-Token' => $csrf,
            ],
            'body' => [
                'target_type' => 'order',
                'target_id' => (string) $order['id'],
                'provider' => 'qris_static',
                'event' => 'PAID',
                'mode' => 'sign',
            ],
        ]);

        $json = $this->jsonResponse($response);
        self::assertSame(422, $response->getStatusCode());
        self::assertFalse($json['ok']);
        self::assertStringContainsString('QRIS statis', (string) $json['error']);
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM simulator_runs'));
    }

    public function testHttpInvalidTargetReturns422AndRecordsFailedRun(): void
    {
        [$sessionId, $csrf] = $this->adminSession();

        $response = $this->http('POST', '/admin/simulator/webhook', [
            'session' => $sessionId,
            'headers' => [
                'Accept' => 'application/json',
                'X-CSRF-Token' => $csrf,
            ],
            'body' => [
                'target_type' => 'order',
                'target_id' => 'missing-order',
                'provider' => 'tripay',
                'event' => 'PAID',
                'mode' => 'sign',
            ],
        ]);

        $json = $this->jsonResponse($response);
        self::assertSame(422, $response->getStatusCode());
        self::assertFalse($json['ok']);
        self::assertStringContainsString('tidak ditemukan', (string) $json['error']);
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM simulator_runs'));
    }

    public function testRunsEndpointReturnsJsonHistory(): void
    {
        [$sessionId] = $this->adminSession();

        $response = $this->http('GET', '/admin/simulator/runs', [
            'session' => $sessionId,
            'headers' => ['Accept' => 'application/json'],
        ]);

        $json = $this->jsonResponse($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($json['ok']);
        self::assertSame([], $json['runs']);
    }
}
