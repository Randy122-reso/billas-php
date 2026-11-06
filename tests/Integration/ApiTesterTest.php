<?php

declare(strict_types=1);

namespace Billas\Tests\Integration;

use Billas\Domain\PaymentGateway\GatewayFactory;
use Billas\Support\Env;
use Billas\Support\Settings;
use RuntimeException;

final class ApiTesterTest extends HttpTestCase
{
    private function envWith(array $overrides): Env
    {
        $env = new Env(sys_get_temp_dir());
        $values = array_merge($env->defaults(), [
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_URL' => 'https://billing.example.test',
            'SESSION_SECRET' => str_repeat('s', 32),
            'ENCRYPTION_KEY' => str_repeat('e', 32),
            'CRON_TOKEN' => str_repeat('c', 32),
            'ADMIN_PASSWORD' => 'super-secret-password-123',
            'WEBHOOK_SECRET' => str_repeat('w', 32),
            'FEATURE_WIFI_CAPTIVE_PORTAL' => 'false',
            'FEATURE_PPOE_QRIS_UNIQUE' => 'false',
        ], $overrides);

        $reflection = new \ReflectionClass($env);
        $reflection->getProperty('values')->setValue($env, $values);
        $reflection->getProperty('loaded')->setValue($env, true);

        return $env;
    }

    public function testProductionGuardThrowsWhenApiTesterFlagTrueWithoutExplicitAllow(): void
    {
        $env = $this->envWith([
            'APP_ENV' => 'production',
            'FEATURE_API_TESTER' => 'true',
            'API_TESTER_ALLOW_PRODUCTION_REQUESTS' => 'false',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('FEATURE_API_TESTER=true tidak diizinkan di production');
        $env->validate();
    }

    public function testProductionGuardPassesWithExplicitAllow(): void
    {
        $env = $this->envWith([
            'APP_ENV' => 'production',
            'FEATURE_API_TESTER' => 'true',
            'API_TESTER_ALLOW_PRODUCTION_REQUESTS' => 'true',
        ]);

        $env->validate();
        self::assertTrue($env->bool('FEATURE_API_TESTER'));
    }

    public function testEnvValidateThrowsOnInvalidMode(): void
    {
        $env = $this->envWith([
            'APP_ENV' => 'local',
            'API_TESTER_MODE' => 'invalid_mode',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('API_TESTER_MODE harus sandbox|production');
        $env->validate();
    }

    public function testEnvValidateThrowsOnInvalidAllowedMethods(): void
    {
        $env = $this->envWith([
            'APP_ENV' => 'local',
            'API_TESTER_ALLOWED_METHODS' => 'GET,POST,PUT',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('API_TESTER_ALLOWED_METHODS hanya boleh berisi GET,POST,DELETE');
        $env->validate();
    }

    public function testRouteRequiresAdminSession(): void
    {
        $response = $this->http('GET', '/admin/api-tester');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/admin/login', $response->getHeaderLine('Location'));
    }

    public function testRouteReturns403WhenFlagOff(): void
    {
        [$sessionId] = $this->adminSession();

        /** @var Settings $settings */
        $settings = $this->service(Settings::class);
        $settings->set('features.api_tester', '0');

        $response = $this->http('GET', '/admin/api-tester', [
            'session' => $sessionId,
        ]);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testGetApiTesterRendersWhenFlagOn(): void
    {
        [$sessionId] = $this->adminSession();

        /** @var Settings $settings */
        $settings = $this->service(Settings::class);
        $settings->set('features.api_tester', '1');

        $response = $this->http('GET', '/admin/api-tester', [
            'session' => $sessionId,
        ]);

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('Gateway API Tester', $body);
        self::assertStringContainsString('id="api-tester-form"', $body);
    }

    public function testPostSendRequiresCsrf(): void
    {
        [$sessionId] = $this->adminSession();

        /** @var Settings $settings */
        $settings = $this->service(Settings::class);
        $settings->set('features.api_tester', '1');

        $response = $this->http('POST', '/admin/api-tester/send', [
            'session' => $sessionId,
            'headers' => ['Accept' => 'application/json'],
            'body' => [
                'provider' => 'xendit',
                'method' => 'GET',
                'endpoint' => 'v2/balance',
            ],
        ]);

        self::assertSame(419, $response->getStatusCode());
    }

    public function testPostSendRejectsNonWhitelistedEndpoint(): void
    {
        [$sessionId, $csrf] = $this->adminSession();

        /** @var Settings $settings */
        $settings = $this->service(Settings::class);
        $settings->set('features.api_tester', '1');

        $response = $this->http('POST', '/admin/api-tester/send', [
            'session' => $sessionId,
            'headers' => [
                'Accept' => 'application/json',
                'X-CSRF-Token' => $csrf,
            ],
            'body' => [
                'provider' => 'xendit',
                'method' => 'GET',
                'endpoint' => 'unwhitelisted/path',
                '_csrf' => $csrf,
            ],
        ]);

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertStringContainsString('tidak di whitelist', $data['error'] ?? $data['message'] ?? '');
    }

    public function testPostSendRejectsAbsoluteUrl(): void
    {
        [$sessionId, $csrf] = $this->adminSession();

        /** @var Settings $settings */
        $settings = $this->service(Settings::class);
        $settings->set('features.api_tester', '1');

        $response = $this->http('POST', '/admin/api-tester/send', [
            'session' => $sessionId,
            'headers' => [
                'Accept' => 'application/json',
                'X-CSRF-Token' => $csrf,
            ],
            'body' => [
                'provider' => 'xendit',
                'method' => 'GET',
                'endpoint' => 'http://169.254.169.254/latest/meta-data/',
                '_csrf' => $csrf,
            ],
        ]);

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertStringContainsString('bukan URL absolut', $data['error'] ?? $data['message'] ?? '');
    }

    public function testGetRunsReturnsJson(): void
    {
        [$sessionId] = $this->adminSession();

        /** @var Settings $settings */
        $settings = $this->service(Settings::class);
        $settings->set('features.api_tester', '1');

        $response = $this->http('GET', '/admin/api-tester/runs', [
            'session' => $sessionId,
        ]);

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('runs', $data);
    }
}
