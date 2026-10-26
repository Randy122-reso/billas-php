<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap.
 *
 * Tests run against a throwaway SQLite database. All values are injected as
 * process environment variables before Env is loaded, so a developer's local
 * .env never affects the suite.
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$tmp = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'billas-tests';
if (!is_dir($tmp) && !mkdir($tmp, 0777, true) && !is_dir($tmp)) {
    fwrite(STDERR, "Cannot create test temp directory: {$tmp}\n");
    exit(1);
}

$values = [
    'APP_ENV' => 'testing',
    'APP_DEBUG' => 'true',
    'APP_URL' => 'http://localhost:8080',
    'BASE_URL' => '',
    'TZ' => 'Asia/Jakarta',
    'LOG_LEVEL' => 'error',
    'LOG_DIR' => $tmp . DIRECTORY_SEPARATOR . 'logs',
    'DB_PATH' => $tmp . DIRECTORY_SEPARATOR . 'bootstrap.db',
    'AUTO_MIGRATE' => '0',
    'AUTO_SEED' => '0',
    'SEED_DEMO' => '0',
    'ADMIN_USERNAME' => 'admin',
    'ADMIN_PASSWORD' => 'admin123',
    'DEMO_CUSTOMER_PASSWORD' => 'pelanggan123',
    'SESSION_SECRET' => 'testing-session-secret-0123456789abcdef',
    'ENCRYPTION_KEY' => 'testing-encryption-key-0123456789abcdef',
    'CRON_TOKEN' => 'testing-cron-token-0123456789abcdef',
    'WEBHOOK_SECRET' => 'testing-webhook-secret-0123456789abcdef',
    'FEATURE_WIFI_CAPTIVE_PORTAL' => 'true',
    'FEATURE_PPOE_QRIS_UNIQUE' => 'true',
    'WIFI_SSID' => 'RTRWNET',
    'WIFI_ALLOWED_SSIDS' => 'RTRWNET',
    'WIFI_BRAND_NAME' => 'RTRWNET',
    'WIFI_PASSWORD_LENGTH' => '8',
    'WIFI_SESSION_TOKEN_TTL' => '1800',
    'WIFI_MAX_PENDING_ORDERS_PER_MAC' => '2',
    'PPPOE_QRIS_UNIQUE_PROVIDERS' => 'midtrans,xendit,duitku',
    'PPPOE_SERVICE_GUARANTEE_DAYS' => '0',
    'MIKROTIK_FAKE' => 'true',
    'PAYMENT_DEFAULT_GATEWAY' => 'qris_static',
    'PAYMENT_FALLBACK_CHAIN' => 'qris_static',
    'PAYMENT_PERSISTENT_PROVIDERS' => 'qris_static',
    'PAYMENT_ORDER_TTL' => '900',
    'QRIS_STATIC_ENABLED' => 'true',
    'QRIS_STATIC_PAYLOAD' => '',
    'QRIS_STATIC_MERCHANT_ID' => 'DEMO000000000000',
    'QRIS_STATIC_MERCHANT_NAME' => 'BILLAS TEST',
    'QRIS_STATIC_CITY' => 'JAKARTA',
    'QRIS_STATIC_POSTAL' => '10000',
    'QRIS_STATIC_UNIQUE_CODE' => 'true',
    'CRON_INTERVAL_INVOICES' => '86400',
    'CRON_INTERVAL_ISOLIR' => '86400',
    'CRON_INTERVAL_VOUCHERS' => '300',
    'CRON_INTERVAL_ORDER_EXPIRE' => '900',
    'CRON_INTERVAL_INVOICE_EXPIRE' => '900',
    'CRON_INTERVAL_BACKUP' => '86400',
    'CRON_INTERVAL_LOGS' => '86400',
    'CRON_INTERVAL_CAPTIVE_SESSIONS' => '900',
    'RETRY_INTERVAL' => '1',
    'RETRY_MAX_ATTEMPTS' => '10',
    'RETRY_BACKOFF_BASE' => '1',
];

foreach ($values as $key => $value) {
    putenv($key . '=' . $value);
    $_ENV[$key] = $value;
}

unset($values, $key, $value);
