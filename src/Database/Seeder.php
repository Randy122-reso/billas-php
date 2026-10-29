<?php

declare(strict_types=1);

namespace Billas\Database;

use Billas\Support\Crypto;
use Billas\Support\Env;
use Billas\Support\Time;
use PDO;

final class Seeder
{
    public function __construct(
        private readonly PDO $db,
        private readonly Env $env,
        private readonly Crypto $crypto,
    ) {
    }

    /** @return array<string,mixed> */
    public function seed(): array
    {
        $summary = ['admin' => false, 'settings' => 0, 'demo' => false];

        $this->seedAdmin($summary);
        $this->seedSettings($summary);
        $this->seedJobs();

        if ($this->env->bool('SEED_DEMO')) {
            $this->seedDemo();
            $summary['demo'] = true;
        }

        return $summary;
    }

    /** @param array<string,mixed> $summary */
    private function seedAdmin(array &$summary): void
    {
        $count = (int) $this->db->query('SELECT COUNT(*) FROM users')->fetchColumn();
        if ($count > 0) {
            return;
        }

        $username = $this->env->string('ADMIN_USERNAME', 'admin');
        $password = $this->env->string('ADMIN_PASSWORD', 'admin123');
        $stmt = $this->db->prepare(
            'INSERT INTO users (username, password_hash, role, active, created_at) VALUES (?, ?, ?, 1, ?)'
        );
        $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), 'superadmin', time()]);
        $summary['admin'] = true;
    }

    /** @param array<string,mixed> $summary */
    private function seedSettings(array &$summary): void
    {
        $defaults = [
            'features.hotspot_calendar_expiry' => $this->env->bool('FEATURE_HOTSPOT_CALENDAR_EXPIRY', true) ? '1' : '0',
            'features.persistent_account' => $this->env->bool('FEATURE_PERSISTENT_ACCOUNT', false) ? '1' : '0',
            'features.subscription_paid_until' => $this->env->bool('FEATURE_SUBSCRIPTION_PAID_UNTIL', true) ? '1' : '0',
            'features.pppoe_qris_unique' => $this->env->bool('FEATURE_PPOE_QRIS_UNIQUE', true) ? '1' : '0',
            'features.wifi_captive_portal' => $this->env->bool('FEATURE_WIFI_CAPTIVE_PORTAL', true) ? '1' : '0',
            'pppoe_qris.auto_create' => $this->env->bool('PPPOE_QRIS_UNIQUE_AUTO_CREATE', true) ? '1' : '0',
            'pppoe_qris.providers' => $this->env->string('PPPOE_QRIS_UNIQUE_PROVIDERS', 'midtrans,xendit,duitku'),
            'pppoe_qris.guarantee_days' => (string) $this->env->int('PPPOE_SERVICE_GUARANTEE_DAYS'),
            'wifi.ssid' => $this->env->string('WIFI_SSID', 'RTRWNET'),
            'wifi.allowed_ssids' => $this->env->string('WIFI_ALLOWED_SSIDS', 'RTRWNET'),
            'wifi.brand_name' => $this->env->string('WIFI_BRAND_NAME', 'RTRWNET'),
            'wifi.logo_url' => $this->env->string('WIFI_LOGO_URL'),
            'wifi.terms_url' => $this->env->string('WIFI_TERMS_URL'),
            'wifi.session_token_ttl' => (string) $this->env->int('WIFI_SESSION_TOKEN_TTL', 1800),
            'wifi.password_length' => (string) $this->env->int('WIFI_PASSWORD_LENGTH', 8),
            'wifi.calendar_days_default' => (string) $this->env->int('WIFI_CALENDAR_DAYS_DEFAULT', 1),
            'wifi.max_pending_orders_per_mac' => (string) $this->env->int('WIFI_MAX_PENDING_ORDERS_PER_MAC', 2),
            'wifi.rate_limit_create_per_ip' => (string) $this->env->int('WIFI_RATE_LIMIT_CREATE_PER_IP', 5),
            'wifi.rate_limit_create_per_mac' => (string) $this->env->int('WIFI_RATE_LIMIT_CREATE_PER_MAC', 3),
            'cron.grace_isolir_days' => (string) $this->env->int('CRON_GRACE_ISOLIR_DAYS', 3),
            'payment.default_gateway' => $this->env->string('PAYMENT_DEFAULT_GATEWAY', 'qris_static'),
            'payment.fallback_chain' => $this->env->string('PAYMENT_FALLBACK_CHAIN', 'qris_static'),
            'payment.order_ttl' => (string) $this->env->int('PAYMENT_ORDER_TTL', 900),
            'webhook_simulator.mode' => $this->env->string('WEBHOOK_SIMULATOR_MODE', 'sign'),
            'webhook_simulator.allow_in_production' => $this->env->bool('WEBHOOK_SIMULATOR_ALLOW_IN_PRODUCTION', false) ? '1' : '0',
        ];

        $stmt = $this->db->prepare('INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)');
        foreach ($defaults as $key => $value) {
            $stmt->execute([$key, $value]);
            $summary['settings'] += $stmt->rowCount();
        }
    }

    private function seedJobs(): void
    {
        $stmt = $this->db->prepare('INSERT OR IGNORE INTO job_state (task, last_run_at, updated_at) VALUES (?, 0, 0)');
        foreach (['invoices', 'isolir', 'vouchers', 'order_expire', 'invoice_expire', 'backup', 'logs', 'captive_sessions'] as $task) {
            $stmt->execute([$task]);
        }
    }

    private function seedDemo(): void
    {
        $routerId = (int) $this->db->query("SELECT id FROM routers WHERE name = 'Demo Router' LIMIT 1")->fetchColumn();
        if ($routerId === 0) {
            $stmt = $this->db->prepare(
                'INSERT INTO routers (name, host, port, username, password_enc, use_tls, active, created_at)
                 VALUES (?, ?, ?, ?, ?, 0, 1, ?)'
            );
            $stmt->execute([
                'Demo Router',
                $this->env->string('MIKROTIK_HOST', '127.0.0.1'),
                $this->env->int('MIKROTIK_PORT', 8728),
                $this->env->string('MIKROTIK_USER', 'billing-api'),
                $this->crypto->encrypt($this->env->string('MIKROTIK_PASS')),
                time(),
            ]);
            $routerId = (int) $this->db->lastInsertId();
        }

        $packageIds = [];
        $packages = [
            ['Hotspot 1 Hari', 'hotspot', 5000, null, null, 'calendar', 1, 'hs-1d', 1],
            ['Hotspot 7 Hari (Uptime)', 'hotspot', 25000, 7, 'days', 'uptime', null, 'hs-7d', 1],
            ['Hotspot 30 Hari', 'hotspot', 75000, null, null, 'calendar', 30, 'hs-30d', 0],
            ['PPPoE Rumah 10 Mbps', 'pppoe', 150000, null, null, 'calendar', 30, 'pppoe-10m', 0],
        ];
        $stmt = $this->db->prepare(
            'INSERT INTO packages
             (name, type, price, duration_value, duration_unit, duration_mode, calendar_days,
              mikrotik_profile, bandwidth_down, bandwidth_up, active, available_on_captive, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($packages as $p) {
            $exists = (int) $this->db->query('SELECT COUNT(*) FROM packages WHERE name = ' . $this->db->quote($p[0]))->fetchColumn();
            if ($exists > 0) {
                continue;
            }
            $stmt->execute([
                $p[0], $p[1], $p[2], $p[3], $p[4], $p[5], $p[6], $p[7],
                $p[1] === 'hotspot' ? 10_000 : 10_000,
                $p[1] === 'hotspot' ? 10_000 : 2_000,
                1, $p[8], time(),
            ]);
            $packageIds[$p[0]] = (int) $this->db->lastInsertId();
        }

        // Demo package rows may already exist from a previous seed; read them
        // back so the demo customer is still created on the second run.
        $stmt = $this->db->query('SELECT id, name FROM packages');
        foreach ($stmt->fetchAll() as $row) {
            $packageIds[(string) $row['name']] = (int) $row['id'];
        }

        $customerExists = (int) $this->db->query("SELECT COUNT(*) FROM customers WHERE code = 'DEMO001'")->fetchColumn();
        if ($customerExists === 0 && isset($packageIds['PPPoE Rumah 10 Mbps'])) {
            $stmt = $this->db->prepare(
                'INSERT INTO customers
                 (code, name, phone, email, pppoe_username, pppoe_password, package_id, router_id,
                  portal_password_hash, status, paid_until, paid_until_updated_at,
                  service_guaranteed_until, service_guaranteed_updated_at, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $until = Time::addDays(Time::today(), 7);
            $stmt->execute([
                'DEMO001',
                'Pelanggan Demo',
                '081234567890',
                'demo@example.test',
                'demo-pppoe',
                'demo-pppoe-secret',
                $packageIds['PPPoE Rumah 10 Mbps'],
                $routerId,
                password_hash($this->env->string('DEMO_CUSTOMER_PASSWORD', 'pelanggan123'), PASSWORD_DEFAULT),
                'active',
                $until,
                time(),
                $until,
                time(),
                time(),
            ]);
        }
    }
}
