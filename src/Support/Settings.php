<?php

declare(strict_types=1);

namespace Billas\Support;

use PDO;

/**
 * Mutable runtime settings. Values saved from the admin UI take precedence over
 * environment defaults; this allows operators to toggle features without
 * rebuilding containers. Environment values remain the seed/default.
 */
final class Settings
{
    /** @var array<string,mixed>|null */
    private ?array $cache = null;

    /** @var array<string,string> */
    private const ENV_MAP = [
        'features.hotspot_calendar_expiry' => 'FEATURE_HOTSPOT_CALENDAR_EXPIRY',
        'features.persistent_account' => 'FEATURE_PERSISTENT_ACCOUNT',
        'features.subscription_paid_until' => 'FEATURE_SUBSCRIPTION_PAID_UNTIL',
        'features.pppoe_qris_unique' => 'FEATURE_PPOE_QRIS_UNIQUE',
        'features.wifi_captive_portal' => 'FEATURE_WIFI_CAPTIVE_PORTAL',
        'pppoe_qris.auto_create' => 'PPPOE_QRIS_UNIQUE_AUTO_CREATE',
        'pppoe_qris.providers' => 'PPPOE_QRIS_UNIQUE_PROVIDERS',
        'pppoe_qris.guarantee_days' => 'PPPOE_SERVICE_GUARANTEE_DAYS',
        'wifi.ssid' => 'WIFI_SSID',
        'wifi.allowed_ssids' => 'WIFI_ALLOWED_SSIDS',
        'wifi.brand_name' => 'WIFI_BRAND_NAME',
        'wifi.logo_url' => 'WIFI_LOGO_URL',
        'wifi.terms_url' => 'WIFI_TERMS_URL',
        'wifi.session_token_ttl' => 'WIFI_SESSION_TOKEN_TTL',
        'wifi.password_length' => 'WIFI_PASSWORD_LENGTH',
        'wifi.calendar_days_default' => 'WIFI_CALENDAR_DAYS_DEFAULT',
        'wifi.max_pending_orders_per_mac' => 'WIFI_MAX_PENDING_ORDERS_PER_MAC',
        'wifi.rate_limit_create_per_ip' => 'WIFI_RATE_LIMIT_CREATE_PER_IP',
        'wifi.rate_limit_create_per_mac' => 'WIFI_RATE_LIMIT_CREATE_PER_MAC',
        'cron.grace_isolir_days' => 'CRON_GRACE_ISOLIR_DAYS',
        'payment.default_gateway' => 'PAYMENT_DEFAULT_GATEWAY',
        'payment.fallback_chain' => 'PAYMENT_FALLBACK_CHAIN',
        'payment.order_ttl' => 'PAYMENT_ORDER_TTL',
    ];

    public function __construct(
        private readonly PDO $db,
        private readonly Env $env,
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();
        if (array_key_exists($key, $all)) {
            return $all[$key];
        }

        $envKey = self::ENV_MAP[$key] ?? null;
        if ($envKey !== null) {
            $value = $this->env->get($envKey);
            if ($value !== null) {
                return $value;
            }
        }

        return $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);
        if ($value === null) {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key);

        return $value === null ? $default : (int) $value;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->get($key);

        return $value === null ? $default : (string) $value;
    }

    public function feature(string $name): bool
    {
        return $this->bool('features.' . $name, false);
    }

    /** @return list<string> */
    public function csv(string $key, string $default = ''): array
    {
        $value = $this->string($key, $default);

        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $v): bool => $v !== ''));
    }

    public function set(string $key, mixed $value): void
    {
        $encoded = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        $stmt = $this->db->prepare(
            'INSERT INTO settings (key, value) VALUES (?, ?)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value'
        );
        $stmt->execute([$key, $encoded]);
        $this->cache = null;
    }

    /** @param array<string,mixed> $values */
    public function setMany(array $values): void
    {
        $this->db->beginTransaction();
        try {
            foreach ($values as $key => $value) {
                $this->set((string) $key, $value);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        } finally {
            $this->cache = null;
        }
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        try {
            $rows = $this->db->query('SELECT key, value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (\Throwable) {
            $rows = [];
        }
        $this->cache = is_array($rows) ? $rows : [];

        return $this->cache;
    }

    public function brand(): string
    {
        return $this->string('wifi.brand_name', 'RTRWNET');
    }

    public function ssid(): string
    {
        return $this->string('wifi.ssid', 'RTRWNET');
    }

    /** @return list<string> */
    public function allowedSsids(): array
    {
        $list = $this->csv('wifi.allowed_ssids', 'RTRWNET');

        return $list === [] ? ['RTRWNET'] : $list;
    }

    /** @return array<string,mixed> */
    public function features(): array
    {
        return [
            'hotspot_calendar_expiry' => $this->feature('hotspot_calendar_expiry'),
            'persistent_account' => $this->feature('persistent_account'),
            'subscription_paid_until' => $this->feature('subscription_paid_until'),
            'pppoe_qris_unique' => $this->feature('pppoe_qris_unique'),
            'wifi_captive_portal' => $this->feature('wifi_captive_portal'),
        ];
    }
}
