<?php

declare(strict_types=1);

namespace Billas\Tests\Unit;

use Billas\Support\Env;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

final class EnvValidationTest extends TestCase
{
    public function testProductionHttpUrlIsRejectedWithoutExplicitOptIn(): void
    {
        $env = $this->envWith([
            'APP_URL' => 'http://192.168.111.1:8080',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ALLOW_HTTP_IN_PRODUCTION');
        $env->validate();
    }

    public function testProductionHttpUrlBootsWithExplicitOptInAndWarns(): void
    {
        $env = $this->envWith([
            'APP_URL' => 'http://192.168.111.1:8080',
            'ALLOW_HTTP_IN_PRODUCTION' => 'true',
        ]);

        $env->validate();

        self::assertStringContainsString('ALLOW_HTTP_IN_PRODUCTION=true', implode("\n", $env->warnings()));
    }

    public function testProductionHttpsUrlBootsWithoutHttpOptIn(): void
    {
        $env = $this->envWith([
            'APP_URL' => 'https://billing.example.test',
        ]);

        $env->validate();

        self::assertSame([], $env->warnings());
    }

    public function testHttpOptInDoesNotAllowOtherSchemes(): void
    {
        $env = $this->envWith([
            'APP_URL' => 'ftp://billing.example.test',
            'ALLOW_HTTP_IN_PRODUCTION' => 'true',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APP_URL production wajib menggunakan https://');
        $env->validate();
    }

    public function testNonLocalEnvironmentRequiresStrongSecrets(): void
    {
        $env = $this->envWith([
            'APP_ENV' => 'staging',
            'APP_URL' => 'https://billing.example.test',
            'SESSION_SECRET' => 'too-short',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SESSION_SECRET wajib diisi minimal 16 karakter');
        $env->validate();
    }

    public function testLocalEnvironmentAllowsWeakSecretsWithWarning(): void
    {
        $env = $this->envWith([
            'APP_ENV' => 'local',
            'APP_URL' => 'http://127.0.0.1:8080',
            'SESSION_SECRET' => 'too-short',
        ]);

        $env->validate();

        self::assertStringContainsString('SESSION_SECRET lemah/kosong', implode("\n", $env->warnings()));
    }

    /** @param array<string,string> $overrides */
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
            'WEBHOOK_SECRET' => str_repeat('w', 32),
            'FEATURE_WIFI_CAPTIVE_PORTAL' => 'false',
            'FEATURE_PPOE_QRIS_UNIQUE' => 'false',
        ], $overrides);

        $reflection = new ReflectionClass($env);
        $reflection->getProperty('values')->setValue($env, $values);
        $reflection->getProperty('loaded')->setValue($env, true);

        return $env;
    }
}
