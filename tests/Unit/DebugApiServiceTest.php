<?php

declare(strict_types=1);

namespace Billas\Tests\Unit;

use Billas\Domain\AuditService;
use Billas\Domain\DebugApiService;
use Billas\Domain\PaymentGateway\GatewayFactory;
use Billas\Domain\PaymentGateway\TripayService;
use Billas\Domain\PaymentGateway\MidtransService;
use Billas\Domain\PaymentGateway\XenditService;
use Billas\Domain\PaymentGateway\DuitkuService;
use Billas\Domain\PaymentGateway\QrisStaticService;
use Billas\Support\Env;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use LogicException;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class DebugApiServiceTest extends TestCase
{
    private PDO $db;
    private Env $env;
    private GatewayFactory $gateways;
    private AuditService $audit;
    protected function tearDown(): void
    {
        putenv('XENDIT_SECRET_KEY');
        unset($_ENV['XENDIT_SECRET_KEY']);
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE api_tester_runs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            provider TEXT NOT NULL,
            method TEXT NOT NULL,
            endpoint TEXT NOT NULL,
            request_body TEXT,
            response_status INTEGER,
            response_body TEXT,
            duration_ms INTEGER,
            error TEXT,
            created_by INTEGER,
            created_at INTEGER NOT NULL
        )');
        $this->db->exec('CREATE TABLE audit_trail (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            actor_type TEXT NOT NULL,
            actor_id TEXT NOT NULL,
            action TEXT NOT NULL,
            entity_type TEXT,
            entity_id TEXT,
            meta TEXT,
            ip TEXT,
            created_at INTEGER NOT NULL
        )');

        $this->env = new Env(dirname(__DIR__, 2));
        $this->gateways = new GatewayFactory($this->env, new NullLogger());
        $this->audit = new AuditService($this->db, new NullLogger());
    }

    public function testSendThrowsOnInvalidMethod(): void
    {
        $service = new DebugApiService($this->db, $this->gateways, $this->audit, [
            'api_tester_allowed_methods' => 'GET,POST,DELETE',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Method tidak valid: PUT');
        $service->send('xendit', 'PUT', 'v2/balance', null);
    }

    public function testSendThrowsOnMethodNotInConfigAllowed(): void
    {
        $service = new DebugApiService($this->db, $this->gateways, $this->audit, [
            'api_tester_allowed_methods' => 'GET',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Method POST tidak diizinkan oleh konfigurasi.');
        $service->send('xendit', 'POST', 'qr_codes', '{}');
    }

    public function testSendThrowsOnOversizedBody(): void
    {
        $service = new DebugApiService($this->db, $this->gateways, $this->audit, [
            'api_tester_allowed_methods' => 'POST',
            'api_tester_max_body_bytes' => 10,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Body melebihi batas ukuran.');
        $service->send('xendit', 'POST', 'qr_codes', '{"data":"toolongbody"}');
    }

    public function testSendThrowsOnInvalidJsonBody(): void
    {
        $service = new DebugApiService($this->db, $this->gateways, $this->audit, [
            'api_tester_allowed_methods' => 'POST',
            'api_tester_max_body_bytes' => 1024,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Body harus berupa JSON valid');
        $service->send('xendit', 'POST', 'qr_codes', 'invalid-json');
    }

    public function testSendThrowsOnAbsoluteUrl(): void
    {
        $service = new DebugApiService($this->db, $this->gateways, $this->audit, [
            'api_tester_allowed_methods' => 'GET',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Endpoint harus path relatif, bukan URL absolut.');
        $service->send('xendit', 'GET', 'https://api.xendit.co/v2/balance', null);
    }

    public function testSendThrowsOnPathNotInWhitelist(): void
    {
        $service = new DebugApiService($this->db, $this->gateways, $this->audit, [
            'api_tester_allowed_methods' => 'GET',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Endpoint tidak di whitelist untuk provider xendit.');
        $service->send('xendit', 'GET', 'unauthorized/endpoint', null);
    }

    public function testSendThrowsForQrisStatic(): void
    {
        $service = new DebugApiService($this->db, $this->gateways, $this->audit, [
            'api_tester_allowed_methods' => 'GET',
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('tidak mendukung API tester');
        $service->send('qris_static', 'GET', 'any', null);
    }

    public function testMaskHeadersReplacesSensitiveHeaders(): void
    {
        $service = new DebugApiService($this->db, $this->gateways, $this->audit, []);
        $headers = [
            'Content-Type' => ['application/json'],
            'Authorization' => ['Bearer my-secret-token'],
            'X-Callback-Signature' => ['abc123sig'],
            'X-Custom' => ['safe-val'],
        ];

        $masked = $service->maskHeaders($headers);
        self::assertSame(['application/json'], $masked['Content-Type']);
        self::assertSame(['***MASKED***'], $masked['Authorization']);
        self::assertSame(['***MASKED***'], $masked['X-Callback-Signature']);
        self::assertSame(['safe-val'], $masked['X-Custom']);
    }

    public function testMaskBodyReplacesSecrets(): void
    {
        $service = new DebugApiService($this->db, $this->gateways, $this->audit, []);
        $body = '{"key":"secret-key-123","val":"test-token"}';
        $masked = $service->maskBody($body, ['secret-key-123', 'test-token']);

        self::assertSame('{"key":"***MASKED***","val":"***MASKED***"}', $masked);
        self::assertStringNotContainsString('secret-key-123', $masked);
    }

    public function testSuccessfulSendWithMockClientAndMasking(): void
    {
        $mockClient = $this->createMock(ClientInterface::class);
        $mockClient->expects(self::once())
            ->method('request')
            ->with('GET', 'https://api.xendit.co/v2/balance', self::anything())
            ->willReturn(new Response(200, [
                'Content-Type' => ['application/json'],
                'Authorization' => ['Basic token-here'],
            ], '{"balance": 1000000, "secret": "secret-val"}'));

        putenv('XENDIT_SECRET_KEY=secret-val');
        $_ENV['XENDIT_SECRET_KEY'] = 'secret-val';
        $env = new Env(dirname(__DIR__, 2));
        $env->load();
        $gateways = new GatewayFactory($env, new NullLogger());

        $service = new DebugApiService(
            $this->db,
            $gateways,
            $this->audit,
            ['api_tester_allowed_methods' => 'GET', 'api_tester_timeout_seconds' => 10],
            1,
            $mockClient
        );

        $res = $service->send('xendit', 'GET', 'v2/balance', null);

        self::assertSame(200, $res['status']);
        self::assertSame(['***MASKED***'], $res['headers']['Authorization']);
        self::assertStringContainsString('***MASKED***', $res['body']);
        self::assertStringNotContainsString('secret-val', $res['body']);

        // Check DB row was created and masked
        $row = $this->db->query('SELECT * FROM api_tester_runs WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        self::assertNotNull($row);
        self::assertSame('xendit', $row['provider']);
        self::assertSame(200, (int) $row['response_status']);
        self::assertStringNotContainsString('secret-val', (string) $row['response_body']);

        // Check audit trail
        $auditRow = $this->db->query("SELECT * FROM audit_trail WHERE action = 'api_tester.dispatch'")->fetch(PDO::FETCH_ASSOC);
        self::assertNotNull($auditRow);
        self::assertSame('xendit:GET', $auditRow['meta']);
    }

    public function testProviderBaseUrlsAndAllowedPaths(): void
    {
        $tripay = new TripayService($this->env, new NullLogger());
        self::assertNotEmpty($tripay->getApiTesterBaseUrl());
        self::assertNotEmpty($tripay->getApiTesterAllowedPaths());
        self::assertNotEmpty($tripay->getApiTesterHeaders());

        $midtrans = new MidtransService($this->env, new NullLogger());
        self::assertNotEmpty($midtrans->getApiTesterBaseUrl());
        self::assertNotEmpty($midtrans->getApiTesterAllowedPaths());
        self::assertNotEmpty($midtrans->getApiTesterHeaders());

        $xendit = new XenditService($this->env, new NullLogger());
        self::assertSame('https://api.xendit.co/', $xendit->getApiTesterBaseUrl());
        self::assertNotEmpty($xendit->getApiTesterAllowedPaths());

        $duitku = new DuitkuService($this->env, new NullLogger());
        self::assertNotEmpty($duitku->getApiTesterBaseUrl());
        self::assertNotEmpty($duitku->getApiTesterAllowedPaths());
    }
}
