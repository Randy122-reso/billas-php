<?php

declare(strict_types=1);

namespace Billas\Tests\Integration;

use Billas\App\Application;
use Billas\Domain\WebhookSimulatorService;
use Billas\Support\Session;
use Billas\Tests\DatabaseTestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Shared fixture for Fitur F integration/HTTP tests.
 *
 * Provider secrets are injected only for this test case and restored in
 * tearDown() so other feature tests keep their original gateway selection.
 */
abstract class WebhookSimulatorTestCase extends DatabaseTestCase
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

    /**
     * Create an authenticated admin session and return its ID + CSRF token.
     *
     * @return array{0:string,1:string}
     */
    protected function adminSession(): array
    {
        /** @var Session $session */
        $session = $this->service(Session::class);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        session_id(bin2hex(random_bytes(16)));
        $session->start();
        $session->loginAdmin(1, 'admin', 'superadmin');
        $sessionId = session_id();
        $csrf = (string) ($_SESSION['_csrf'] ?? '');
        session_write_close();

        return [$sessionId, $csrf];
    }

    /**
     * Dispatch a request through the real Slim application/middleware stack.
     *
     * @param array{headers?:array<string,string>,body?:array<string,mixed>|null,session?:string} $options
     */
    protected function http(string $method, string $path, array $options = []): ResponseInterface
    {
        $app = (new Application(
            $this->services['container'],
            $this->services['env'],
            $this->services['logger'],
        ))->app();

        $request = (new ServerRequestFactory())->createServerRequest(
            $method,
            $path,
            ['REMOTE_ADDR' => '127.0.0.1']
        );

        foreach ($options['headers'] ?? [] as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if (array_key_exists('body', $options)) {
            $request = $request->withParsedBody($options['body']);
        }
        if (isset($options['session'])) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            session_id((string) $options['session']);
        }

        return $app->handle($request);
    }

    /** @return array<string,mixed> */
    protected function jsonResponse(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
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
