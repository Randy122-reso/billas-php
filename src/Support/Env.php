<?php

declare(strict_types=1);

namespace Billas\Support;

use RuntimeException;

/**
 * Environment loader + validator.
 *
 * `.env` is parsed without third-party dependencies. Real process environment
 * variables always win over .env entries, which makes container deployments
 * predictable.
 */
final class Env
{
    private static ?self $instance = null;

    /** @var array<string,string> */
    private array $values = [];

    /** @var list<string> */
    private array $warnings = [];

    private bool $loaded = false;

    public function __construct(private readonly string $root)
    {
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            throw new RuntimeException('Env belum diinisialisasi.');
        }

        return self::$instance;
    }

    public static function setInstance(self $env): void
    {
        self::$instance = $env;
    }

    public function load(): self
    {
        if ($this->loaded) {
            return $this;
        }

        $this->loaded = true;
        $file = $this->root . DIRECTORY_SEPARATOR . '.env';
        $parsed = is_file($file) ? $this->parseFile($file) : [];
        $this->values = array_merge($this->defaults(), $parsed);

        // Process environment wins (important for Docker / CI).
        foreach (array_keys($this->values) as $key) {
            $runtime = getenv($key);
            if ($runtime !== false && $runtime !== '') {
                $this->values[$key] = $runtime;
            }
            if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
                $this->values[$key] = (string) $_ENV[$key];
            }
        }

        // Make values visible to legacy PHP code and libraries.
        foreach ($this->values as $key => $value) {
            $_ENV[$key] = $value;
            if (getenv($key) === false) {
                putenv($key . '=' . $value);
            }
        }

        self::$instance = $this;

        return $this;
    }

    /** @return array<string,string> */
    public function defaults(): array
    {
        return [
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_VERSION' => '4.0.0',
            'APP_URL' => 'http://localhost:8080',
            'BASE_URL' => '',
            'TZ' => 'Asia/Jakarta',
            'LOG_LEVEL' => 'info',
            'LOG_DIR' => $this->root . DIRECTORY_SEPARATOR . 'logs',
            'DB_PATH' => $this->root . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'billas.db',
            'APP_LISTEN_PORT' => '8080',
            'AUTO_MIGRATE' => '0',
            'AUTO_SEED' => '0',
            'SEED_DEMO' => '0',
            'ADMIN_USERNAME' => 'admin',
            'ADMIN_PASSWORD' => 'admin123',
            'DEMO_CUSTOMER_PASSWORD' => 'pelanggan123',

            'HOTSPOT_ENABLED' => 'true',
            'PPPOE_ENABLED' => 'true',

            'FEATURE_HOTSPOT_CALENDAR_EXPIRY' => 'true',
            'FEATURE_PERSISTENT_ACCOUNT' => 'false',
            'FEATURE_SUBSCRIPTION_PAID_UNTIL' => 'true',
            'FEATURE_PPOE_QRIS_UNIQUE' => 'true',
            'FEATURE_WIFI_CAPTIVE_PORTAL' => 'true',
            'FEATURE_WEBHOOK_SIMULATOR' => 'false',

            'WEBHOOK_SIMULATOR_MODE' => 'sign',
            'WEBHOOK_SIMULATOR_ALLOW_IN_PRODUCTION' => 'false',

            'PPPOE_QRIS_UNIQUE_AUTO_CREATE' => 'true',
            'PPPOE_QRIS_UNIQUE_PROVIDERS' => 'midtrans,xendit,duitku',
            'PPPOE_SERVICE_GUARANTEE_DAYS' => '0',

            'WIFI_SSID' => 'RTRWNET',
            'WIFI_ALLOWED_SSIDS' => 'RTRWNET',
            'WIFI_BRAND_NAME' => 'RTRWNET',
            'WIFI_LOGO_URL' => '',
            'WIFI_TERMS_URL' => '',
            'WIFI_SESSION_TOKEN_TTL' => '1800',
            'WIFI_PASSWORD_LENGTH' => '8',
            'WIFI_CALENDAR_DAYS_DEFAULT' => '1',
            'WIFI_MAX_PENDING_ORDERS_PER_MAC' => '2',
            'WIFI_RATE_LIMIT_CREATE_PER_IP' => '5',
            'WIFI_RATE_LIMIT_CREATE_PER_MAC' => '3',

            'CRON_ENABLED' => 'true',
            'CRON_TOKEN' => '',
            'CRON_HMAC_ENABLED' => 'false',
            'CRON_INTERVAL_INVOICES' => '86400',
            'CRON_INTERVAL_ISOLIR' => '86400',
            'CRON_INTERVAL_VOUCHERS' => '300',
            'CRON_INTERVAL_ORDER_EXPIRE' => '900',
            'CRON_INTERVAL_INVOICE_EXPIRE' => '900',
            'CRON_INTERVAL_BACKUP' => '86400',
            'CRON_INTERVAL_LOGS' => '86400',
            'CRON_INTERVAL_CAPTIVE_SESSIONS' => '900',
            'CRON_GRACE_ISOLIR_DAYS' => '3',

            'RETRY_INTERVAL' => '60',
            'RETRY_MAX_ATTEMPTS' => '10',
            'RETRY_BACKOFF_BASE' => '60',

            'MIKROTIK_HOST' => '127.0.0.1',
            'MIKROTIK_PORT' => '8728',
            'MIKROTIK_USER' => 'billing-api',
            'MIKROTIK_PASS' => '',
            'MIKROTIK_TLS' => 'false',
            'MIKROTIK_FAKE' => 'false',
            'MIKROTIK_TIMEOUT' => '5',

            'PAYMENT_DEFAULT_GATEWAY' => 'qris_static',
            'PAYMENT_FALLBACK_CHAIN' => 'tripay,midtrans,xendit,duitku,qris_static',
            'PAYMENT_PERSISTENT_PROVIDERS' => 'midtrans,xendit,duitku',
            'PAYMENT_ORDER_TTL' => '900',

            'TRIPAY_MODE' => 'sandbox',
            'TRIPAY_API_KEY' => '',
            'TRIPAY_PRIVATE_KEY' => '',
            'TRIPAY_MERCHANT_CODE' => '',
            'TRIPAY_METHOD' => 'QRIS',

            'MIDTRANS_MODE' => 'sandbox',
            'MIDTRANS_SERVER_KEY' => '',
            'MIDTRANS_CLIENT_KEY' => '',

            'XENDIT_MODE' => 'sandbox',
            'XENDIT_SECRET_KEY' => '',
            'XENDIT_WEBHOOK_TOKEN' => '',
            'XENDIT_VA_BANK' => 'BCA',

            'DUITKU_MODE' => 'sandbox',
            'DUITKU_MERCHANT_CODE' => '',
            'DUITKU_API_KEY' => '',

            'QRIS_STATIC_ENABLED' => 'true',
            'QRIS_STATIC_PAYLOAD' => '',
            'QRIS_STATIC_MERCHANT_ID' => '',
            'QRIS_STATIC_MERCHANT_NAME' => '',
            'QRIS_STATIC_CITY' => '',
            'QRIS_STATIC_POSTAL' => '',
            'QRIS_STATIC_UNIQUE_CODE' => 'true',

            'ENCRYPTION_KEY' => '',
            'SESSION_SECRET' => '',
            'WEBHOOK_SECRET' => '',

            'CLOUDFLARE_TUNNEL_TOKEN' => '',
        ];
    }

    /** @return array<string,string> */
    private function parseFile(string $file): array
    {
        $result = [];
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return [];
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = substr($line, 7);
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));
            if ($key === '') {
                continue;
            }
            if (strlen($value) >= 2) {
                $first = $value[0];
                $last = $value[strlen($value) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                    if ($first === '"') {
                        $value = stripcslashes($value);
                    }
                } else {
                    // Strip trailing inline comment when preceded by whitespace.
                    $value = preg_replace('/\s+#.*$/', '', $value) ?? $value;
                }
            }
            $result[$key] = $value;
        }

        return $result;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $value = $this->values[$key] ?? null;
        if ($value === null || $value === '') {
            return $default;
        }

        return $value;
    }

    public function string(string $key, string $default = ''): string
    {
        return (string) $this->get($key, $default);
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);
        if ($value === null) {
            return $default;
        }

        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key);
        return $value === null ? $default : (int) $value;
    }

    /** @return list<string> */
    public function csv(string $key, string $default = ''): array
    {
        $value = $this->get($key, $default) ?? '';
        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $v): bool => $v !== ''));
    }

    /** @return array<string,string> */
    public function all(): array
    {
        return $this->values;
    }

    public function isProduction(): bool
    {
        return $this->string('APP_ENV', 'production') === 'production';
    }

    public function isLocal(): bool
    {
        return in_array($this->string('APP_ENV', 'production'), ['local', 'development', 'dev', 'testing'], true);
    }

    public function root(): string
    {
        return rtrim($this->root, DIRECTORY_SEPARATOR);
    }

    public function path(string $relative = ''): string
    {
        $relative = ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative), DIRECTORY_SEPARATOR);

        return $relative === '' ? $this->root() : $this->root() . DIRECTORY_SEPARATOR . $relative;
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * Fail-fast validation for config that can break billing/security.
     */
    public function validate(): void
    {
        foreach (['pdo_sqlite', 'openssl', 'mbstring', 'curl', 'json'] as $ext) {
            if (!extension_loaded($ext)) {
                throw new RuntimeException("Ekstensi PHP wajib tidak aktif: {$ext}");
            }
        }

        $required = [
            'SESSION_SECRET',
            'ENCRYPTION_KEY',
            'CRON_TOKEN',
        ];
        foreach ($required as $key) {
            if (strlen((string) $this->get($key, '')) < 16) {
                if ($this->isProduction() || !$this->isLocal()) {
                    throw new RuntimeException("{$key} wajib diisi minimal 16 karakter. Lihat .env.example.");
                }
                $this->warnings[] = "{$key} lemah/kosong; hanya diperbolehkan untuk mode lokal.";
            }
        }

        if ($this->isProduction()) {
            if ($this->bool('APP_DEBUG')) {
                throw new RuntimeException('APP_DEBUG=true tidak diperbolehkan di production.');
            }
            $url = $this->string('APP_URL');
            if (!str_starts_with($url, 'https://')) {
                throw new RuntimeException('APP_URL production wajib menggunakan https://.');
            }
        }

        // Fitur F: simulator tidak boleh aktif di production tanpa izin eksplisit.
        $simulatorMode = strtolower(trim($this->string('WEBHOOK_SIMULATOR_MODE', 'sign')));
        if ($this->isProduction()
            && $this->bool('FEATURE_WEBHOOK_SIMULATOR')
            && !$this->bool('WEBHOOK_SIMULATOR_ALLOW_IN_PRODUCTION')) {
            throw new RuntimeException(
                'FEATURE_WEBHOOK_SIMULATOR=true tidak diizinkan di production. '
                . 'Set WEBHOOK_SIMULATOR_ALLOW_IN_PRODUCTION=true jika ini disengaja.'
            );
        }
        if (!in_array($simulatorMode, ['sign', 'bypass'], true)) {
            throw new RuntimeException('WEBHOOK_SIMULATOR_MODE harus sign|bypass.');
        }
        if ($this->bool('FEATURE_WEBHOOK_SIMULATOR')
            && $simulatorMode === 'sign'
            && $this->configuredWebhookSigningProviders() === []) {
            throw new RuntimeException('Mode sign membutuhkan minimal satu secret gateway terkonfigurasi.');
        }

        if ($this->bool('FEATURE_WIFI_CAPTIVE_PORTAL')) {
            $ssid = trim($this->string('WIFI_SSID'));
            if ($ssid === '') {
                throw new RuntimeException('FEATURE_WIFI_CAPTIVE_PORTAL=true mewajibkan WIFI_SSID.');
            }
            $allowed = $this->csv('WIFI_ALLOWED_SSIDS', 'RTRWNET');
            if (!in_array($ssid, $allowed, true)) {
                throw new RuntimeException('WIFI_SSID harus ada di WIFI_ALLOWED_SSIDS.');
            }
        }

        if ($this->bool('FEATURE_WIFI_CAPTIVE_PORTAL') || $this->bool('FEATURE_PPOE_QRIS_UNIQUE')) {
            if ($this->configuredPaymentProviders() === []) {
                $message = 'Fitur QRIS aktif tetapi tidak ada gateway yang terkonfigurasi.';
                if ($this->isProduction()) {
                    throw new RuntimeException($message);
                }
                $this->warnings[] = $message . ' Mode lokal akan memakai QRIS statis demo.';
            }
        }

        if ($this->isProduction() && $this->bool('MIKROTIK_FAKE')) {
            $this->warnings[] = 'MIKROTIK_FAKE=true di production; push router tidak akan terjadi.';
        }

        if ($this->isProduction() && $this->string('WEBHOOK_SECRET') === '') {
            $this->warnings[] = 'WEBHOOK_SECRET kosong; verifikasi signature tetap dilakukan per provider.';
        }
    }

    /** @return list<string> */
    public function configuredWebhookSigningProviders(): array
    {
        return array_values(array_filter(
            $this->configuredPaymentProviders(),
            static fn (string $provider): bool => $provider !== 'qris_static'
        ));
    }

    /** @return list<string> */
    public function configuredPaymentProviders(): array
    {
        $providers = [];
        if ($this->string('TRIPAY_API_KEY') !== '' && $this->string('TRIPAY_PRIVATE_KEY') !== '' && $this->string('TRIPAY_MERCHANT_CODE') !== '') {
            $providers[] = 'tripay';
        }
        if ($this->string('MIDTRANS_SERVER_KEY') !== '') {
            $providers[] = 'midtrans';
        }
        if ($this->string('XENDIT_SECRET_KEY') !== '') {
            $providers[] = 'xendit';
        }
        if ($this->string('DUITKU_MERCHANT_CODE') !== '' && $this->string('DUITKU_API_KEY') !== '') {
            $providers[] = 'duitku';
        }
        if ($this->bool('QRIS_STATIC_ENABLED') && ($this->string('QRIS_STATIC_PAYLOAD') !== '' || $this->string('QRIS_STATIC_MERCHANT_ID') !== '' || !$this->isProduction())) {
            $providers[] = 'qris_static';
        }

        return $providers;
    }
}
