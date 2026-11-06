<?php

declare(strict_types=1);

namespace Billas\Tests\Integration;

use Billas\Domain\WebhookSimulatorService;

/**
 * Shared fixture for Fitur F integration/HTTP tests.
 *
 * Provider secrets are injected only for this test case and restored in
 * tearDown() so other feature tests keep their original gateway selection.
 */
abstract class WebhookSimulatorTestCase extends HttpTestCase
{
    /** @var array<string,string> */
    private const ENV_VALUES = [
        'FEATURE_WEBHOOK_SIMULATOR' => 'true',
        'WEBHOOK_SIMULATOR_MODE' => 'sign',
        'WEBHOOK_SIMULATOR_ALLOW_IN_PRODUCTION' => 'false',
        'TRIPAY_API_KEY' => 'test-tripay-api-key',
        'TRIPAY_PRIVATE_KEY' => 'test-tripay-private-key',
        'TRIPAY_MERCHANT_CODE' => 'T0001',
        'MIDTRANS_SERVER_KEY' => 'test-midtrans-server-key',
        'XENDIT_SECRET_KEY' => 'test-xendit-secret-key',
        'XENDIT_WEBHOOK_TOKEN' => 'test-xendit-webhook-token',
        'DUITKU_MERCHANT_CODE' => 'D0001',
        'DUITKU_API_KEY' => 'test-duitku-api-key',
        'QRIS_STATIC_ENABLED' => 'true',
    ];

    /** @var array<string,string|false> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        foreach (array_keys(self::ENV_VALUES) as $key) {
            $this->originalEnv[$key] = getenv($key);
        }
        foreach (self::ENV_VALUES as $key => $value) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }

        parent::setUp();
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        parent::tearDown();

        foreach ($this->originalEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key]);
            } else {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
            }
        }
        session_id('');
    }

    protected function simulator(): WebhookSimulatorService
    {
        /** @var WebhookSimulatorService $simulator */
        $simulator = $this->service(WebhookSimulatorService::class);

        return $simulator;
    }

    /** @param array<string,mixed> $overrides */
    protected function createPaymentTransaction(int $invoiceId, array $overrides = []): int
    {
        $row = array_merge([
            'invoice_id' => $invoiceId,
            'gateway' => 'midtrans',
            'merchant_ref' => 'INV-TX-' . strtoupper(bin2hex(random_bytes(5))),
            'gateway_reference' => null,
            'amount' => 150000,
            'status' => 'pending',
            'qr_string' => null,
            'payload' => '{}',
            'expires_at' => time() + 900,
        ], $overrides);

        $now = time();
        $this->db()->prepare(
            'INSERT INTO payment_transactions
             (invoice_id, gateway, merchant_ref, gateway_reference, amount, status, qr_string, payload,
              expires_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $row['invoice_id'], $row['gateway'], $row['merchant_ref'], $row['gateway_reference'],
            $row['amount'], $row['status'], $row['qr_string'], $row['payload'],
            $row['expires_at'], $now, $now,
        ]);

        return (int) $this->db()->lastInsertId();
    }
}
