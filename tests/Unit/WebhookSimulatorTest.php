<?php

declare(strict_types=1);

namespace Billas\Tests\Unit;

use Billas\Domain\PaymentGateway\BaseProvider;
use Billas\Domain\WebhookSimulatorService;
use Billas\Support\Env;
use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use RuntimeException;

/**
 * Fitur F (v4.2) — unit-level guards, masking, and default provider contract.
 */
final class WebhookSimulatorTest extends TestCase
{
    public function testMaskSignatureMasksJsonBodyAndSignatureHeaders(): void
    {
        $simulator = $this->simulatorWithoutConstructor();
        $rawBody = (string) json_encode([
            'order_id' => 'BLS-1',
            'signature_key' => 'midtrans-secret-signature',
            'gross_amount' => '150000.00',
        ]);
        $masked = $simulator->maskSignature($rawBody, [
            'X-Signature-Key' => 'midtrans-secret-signature',
            'X-Billas-Simulated' => '1',
        ]);

        $envelope = json_decode($masked, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('***MASKED***', $envelope['headers']['X-Signature-Key']);
        self::assertSame('1', $envelope['headers']['X-Billas-Simulated']);

        $body = json_decode($envelope['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('***MASKED***', $body['signature_key']);
        self::assertSame('BLS-1', $body['order_id']);
        self::assertStringNotContainsString('midtrans-secret-signature', $masked);
    }

    public function testMaskSignatureMasksFormUrlEncodedBodies(): void
    {
        $simulator = $this->simulatorWithoutConstructor();
        $rawBody = http_build_query([
            'merchantCode' => 'D0001',
            'amount' => '150000',
            'merchantOrderId' => 'BLS-2',
            'signature' => 'duitku-secret-signature',
        ]);

        $envelope = json_decode(
            $simulator->maskSignature($rawBody, ['Authorization' => 'Bearer secret']),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        parse_str($envelope['body'], $body);

        self::assertSame('***MASKED***', $body['signature']);
        self::assertSame('BLS-2', $body['merchantOrderId']);
        self::assertSame('***MASKED***', $envelope['headers']['Authorization']);
        self::assertStringNotContainsString('duitku-secret-signature', $envelope['body']);
        self::assertStringNotContainsString('Bearer secret', $envelope['body']);
    }

    public function testDefaultProviderSimulatorHookThrowsLogicException(): void
    {
        $provider = new class (new Env(sys_get_temp_dir()), new NullLogger()) extends BaseProvider {
            public function name(): string
            {
                return 'dummy';
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function createTransaction(array $context): array
            {
                return [];
            }

            public function verifyWebhook(array $headers, string $rawBody): bool
            {
                return true;
            }

            public function normalizeWebhook(array $headers, string $rawBody): array
            {
                return [
                    'merchant_ref' => null,
                    'external_ref' => null,
                    'amount' => 0,
                    'status' => 'pending',
                    'gateway_reference' => null,
                    'paid_at' => null,
                    'event_key' => 'dummy:none',
                    'kind' => 'auto',
                ];
            }

            public function createPersistentAccount(array $context): array
            {
                return ['external_ref' => 'DUMMY'];
            }

            public function supportsPersistent(string $accountType = 'va'): bool
            {
                return false;
            }
        };

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('belum mendukung simulator');
        $provider->buildSimulatedWebhook(['id' => 'x'], 'PAID');
    }

    public function testProductionSimulatorRequiresExplicitAllowFlag(): void
    {
        $env = $this->envWith([
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_URL' => 'https://billing.example.test',
            'SESSION_SECRET' => str_repeat('s', 32),
            'ENCRYPTION_KEY' => str_repeat('e', 32),
            'CRON_TOKEN' => str_repeat('c', 32),
            'FEATURE_WIFI_CAPTIVE_PORTAL' => 'false',
            'FEATURE_PPOE_QRIS_UNIQUE' => 'false',
            'FEATURE_WEBHOOK_SIMULATOR' => 'true',
            'WEBHOOK_SIMULATOR_MODE' => 'sign',
            'WEBHOOK_SIMULATOR_ALLOW_IN_PRODUCTION' => 'false',
            'TRIPAY_API_KEY' => 'test-api-key',
            'TRIPAY_PRIVATE_KEY' => 'test-private-key',
            'TRIPAY_MERCHANT_CODE' => 'T0001',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('WEBHOOK_SIMULATOR_ALLOW_IN_PRODUCTION');
        $env->validate();
    }

    public function testProductionSimulatorBootsWithExplicitAllowFlag(): void
    {
        $env = $this->envWith([
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_URL' => 'https://billing.example.test',
            'SESSION_SECRET' => str_repeat('s', 32),
            'ENCRYPTION_KEY' => str_repeat('e', 32),
            'CRON_TOKEN' => str_repeat('c', 32),
            'FEATURE_WIFI_CAPTIVE_PORTAL' => 'false',
            'FEATURE_PPOE_QRIS_UNIQUE' => 'false',
            'FEATURE_WEBHOOK_SIMULATOR' => 'true',
            'WEBHOOK_SIMULATOR_MODE' => 'sign',
            'WEBHOOK_SIMULATOR_ALLOW_IN_PRODUCTION' => 'true',
            'TRIPAY_API_KEY' => 'test-api-key',
            'TRIPAY_PRIVATE_KEY' => 'test-private-key',
            'TRIPAY_MERCHANT_CODE' => 'T0001',
        ]);

        $env->validate();

        self::assertSame('production', $env->string('APP_ENV'));
        self::assertTrue($env->bool('FEATURE_WEBHOOK_SIMULATOR'));
    }

    public function testInvalidSimulatorModeIsRejectedEvenWhenFeatureIsOff(): void
    {
        $env = $this->envWith([
            'APP_ENV' => 'testing',
            'FEATURE_WEBHOOK_SIMULATOR' => 'false',
            'WEBHOOK_SIMULATOR_MODE' => 'wild',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('WEBHOOK_SIMULATOR_MODE');
        $env->validate();
    }

    public function testSignModeRequiresAtLeastOneSigningProvider(): void
    {
        $env = $this->envWith([
            'APP_ENV' => 'testing',
            'FEATURE_WIFI_CAPTIVE_PORTAL' => 'false',
            'FEATURE_PPOE_QRIS_UNIQUE' => 'false',
            'FEATURE_WEBHOOK_SIMULATOR' => 'true',
            'WEBHOOK_SIMULATOR_MODE' => 'sign',
            'TRIPAY_API_KEY' => '',
            'TRIPAY_PRIVATE_KEY' => '',
            'TRIPAY_MERCHANT_CODE' => '',
            'MIDTRANS_SERVER_KEY' => '',
            'XENDIT_SECRET_KEY' => '',
            'DUITKU_MERCHANT_CODE' => '',
            'DUITKU_API_KEY' => '',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Mode sign membutuhkan');
        $env->validate();
    }

    public function testBypassModeDoesNotRequireGatewaySecrets(): void
    {
        $env = $this->envWith([
            'APP_ENV' => 'testing',
            'FEATURE_WIFI_CAPTIVE_PORTAL' => 'false',
            'FEATURE_PPOE_QRIS_UNIQUE' => 'false',
            'FEATURE_WEBHOOK_SIMULATOR' => 'true',
            'WEBHOOK_SIMULATOR_MODE' => 'bypass',
            'TRIPAY_API_KEY' => '',
            'TRIPAY_PRIVATE_KEY' => '',
            'TRIPAY_MERCHANT_CODE' => '',
            'MIDTRANS_SERVER_KEY' => '',
            'XENDIT_SECRET_KEY' => '',
            'DUITKU_MERCHANT_CODE' => '',
            'DUITKU_API_KEY' => '',
        ]);

        $env->validate();

        self::assertSame('bypass', $env->string('WEBHOOK_SIMULATOR_MODE'));
    }

    private function simulatorWithoutConstructor(): WebhookSimulatorService
    {
        /** @var WebhookSimulatorService $simulator */
        $simulator = (new ReflectionClass(WebhookSimulatorService::class))->newInstanceWithoutConstructor();

        return $simulator;
    }

    /** @param array<string,string> $overrides */
    private function envWith(array $overrides): Env
    {
        $env = new Env(sys_get_temp_dir());
        $values = array_merge($env->defaults(), $overrides);

        $reflection = new ReflectionClass($env);
        $valuesProperty = $reflection->getProperty('values');
        $valuesProperty->setValue($env, $values);
        $loadedProperty = $reflection->getProperty('loaded');
        $loadedProperty->setValue($env, true);

        return $env;
    }
}
