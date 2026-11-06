<?php

declare(strict_types=1);

use Billas\Database\Migrator;

/**
 * v4.2 settings backfill.
 *
 * Existing databases may predate one or more runtime settings keys. Fill only
 * missing rows so admin-visible values are DB-backed while preserving any value
 * an operator has already saved. Environment values remain the seed defaults.
 */
return static function (PDO $db, Migrator $migrator): void {
    $bool = static function (string $key, bool $default): string {
        $value = getenv($key);
        if ($value === false || $value === '') {
            return $default ? '1' : '0';
        }

        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true) ? '1' : '0';
    };
    $string = static function (string $key, string $default): string {
        $value = getenv($key);

        return $value === false || trim($value) === '' ? $default : trim($value);
    };

    $defaults = [
        'features.hotspot_calendar_expiry' => $bool('FEATURE_HOTSPOT_CALENDAR_EXPIRY', true),
        'features.persistent_account' => $bool('FEATURE_PERSISTENT_ACCOUNT', false),
        'features.subscription_paid_until' => $bool('FEATURE_SUBSCRIPTION_PAID_UNTIL', true),
        'features.pppoe_qris_unique' => $bool('FEATURE_PPOE_QRIS_UNIQUE', true),
        'features.wifi_captive_portal' => $bool('FEATURE_WIFI_CAPTIVE_PORTAL', true),
        'features.webhook_simulator' => $bool('FEATURE_WEBHOOK_SIMULATOR', false),
        'payment.default_gateway' => $string('PAYMENT_DEFAULT_GATEWAY', 'qris_static'),
        'payment.fallback_chain' => $string('PAYMENT_FALLBACK_CHAIN', 'qris_static'),
    ];

    $stmt = $db->prepare('INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)');
    foreach ($defaults as $key => $value) {
        $stmt->execute([$key, $value]);
    }
};
