<?php

declare(strict_types=1);

namespace Billas\Domain;

use Billas\Support\Settings;
use Billas\Support\Time;
use PDO;
use RuntimeException;

final class VoucherService
{
    private const PASSWORD_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly PDO $db,
        private readonly Settings $settings,
        private readonly AuditService $audit,
        private readonly RetryService $retry,
        private readonly RouterService $routers,
    ) {
    }

    /** Web portal hotspot voucher: separate username and password. */
    public function persist(array $order, array $package, array $context = []): array
    {
        $existing = $this->findByOrderId((string) $order['id']);
        if ($existing !== null) {
            return $existing;
        }

        $code = $this->uniqueCode();
        $password = $this->generatePassword(8);
        $duration = $this->durationFor($package, 'web_portal');
        $routerId = $context['router_id'] ?? $this->routers->defaultId();
        $now = Time::now();

        $stmt = $this->db->prepare(
            'INSERT INTO vouchers
             (order_id, code, password, mikrotik_profile, duration_value, duration_unit, duration_mode,
              expires_at, router_id, pushed_to_router, source, ssid, client_ip, client_mac, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $order['id'], $code, $password, $package['mikrotik_profile'],
            $duration['value'], $duration['unit'], $duration['mode'], $duration['expires_at'],
            $routerId, 'web_portal', null, $context['client_ip'] ?? $order['client_ip'] ?? null,
            $context['client_mac'] ?? $order['client_mac'] ?? null, $now,
        ]);

        $voucher = $this->findByOrderId((string) $order['id']);
        if ($voucher === null) {
            throw new RuntimeException('Gagal menyimpan voucher.');
        }

        $this->audit->record('system', 'webhook', 'voucher.created', 'voucher', (string) $voucher['id'], [
            'order_id' => $order['id'],
            'source' => 'web_portal',
        ]);
        $this->retry->enqueue('hotspot_push', ['voucher_id' => (int) $voucher['id']]);

        return $voucher;
    }

    /**
     * Fitur E: captive portal voucher. The WiFi password is also the hotspot
     * username, so the customer only has to remember one secret.
     */
    public function persistCaptive(array $order, array $package, array $context = []): array
    {
        $existing = $this->findByOrderId((string) $order['id']);
        if ($existing !== null) {
            return $existing;
        }

        $length = max(8, min(12, $this->settings->int('wifi.password_length', 8)));
        $password = $this->uniquePassword($length);
        $duration = $this->durationFor($package, 'captive_portal');
        $routerId = $context['router_id'] ?? null;
        if ($routerId === null) {
            $routerId = $order['router_id'] ?? $this->routers->defaultId();
        }
        $now = Time::now();

        $stmt = $this->db->prepare(
            'INSERT INTO vouchers
             (order_id, code, password, mikrotik_profile, duration_value, duration_unit, duration_mode,
              expires_at, router_id, pushed_to_router, source, ssid, client_ip, client_mac, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $order['id'], $password, $password, $package['mikrotik_profile'],
            $duration['value'], $duration['unit'], $duration['mode'], $duration['expires_at'],
            $routerId, 'captive_portal', $order['ssid'] ?? null,
            $order['client_ip'] ?? null, $order['client_mac'] ?? null, $now,
        ]);

        $voucher = $this->findByOrderId((string) $order['id']);
        if ($voucher === null) {
            throw new RuntimeException('Gagal menyimpan voucher captive.');
        }

        $this->audit->record('system', 'webhook', 'captive.voucher_issued', 'voucher', (string) $voucher['id'], [
            'order_id' => $order['id'],
            'ssid' => $order['ssid'] ?? null,
        ]);
        $this->retry->enqueue('hotspot_push', ['voucher_id' => (int) $voucher['id']]);

        return $voucher;
    }

    /** @return array<string,mixed>|null */
    public function findByOrderId(string $orderId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM vouchers WHERE order_id = ?');
        $stmt->execute([$orderId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM vouchers WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return list<array<string,mixed>> */
    public function listAdmin(string $source = '', string $status = ''): array
    {
        $sql = 'SELECT v.*, o.merchant_ref, o.amount, o.gateway, p.name AS package_name
                FROM vouchers v
                JOIN orders o ON o.id = v.order_id
                JOIN packages p ON p.id = o.package_id
                WHERE 1 = 1';
        $params = [];
        if (in_array($source, ['web_portal', 'captive_portal'], true)) {
            $sql .= ' AND v.source = ?';
            $params[] = $source;
        }
        if ($status === 'active') {
            $sql .= ' AND v.revoked = 0';
        } elseif ($status === 'revoked') {
            $sql .= ' AND v.revoked = 1';
        }
        $sql .= ' ORDER BY v.id DESC LIMIT 300';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @return array{expired:int} */
    public function expireDue(): array
    {
        $now = Time::now();
        $stmt = $this->db->prepare(
            'SELECT id FROM vouchers
             WHERE revoked = 0 AND expires_at IS NOT NULL AND expires_at <= ?'
        );
        $stmt->execute([$now]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        foreach ($ids as $id) {
            $this->revoke($id, 'expired', 'system');
        }

        return ['expired' => count($ids)];
    }

    public function revoke(int $id, string $reason = 'manual', string $actorId = 'admin'): bool
    {
        $voucher = $this->find($id);
        if ($voucher === null || (int) $voucher['revoked'] === 1) {
            return false;
        }
        $now = Time::now();
        $this->db->prepare(
            'UPDATE vouchers SET revoked = 1, revoked_at = ?, revoke_reason = ? WHERE id = ?'
        )->execute([$now, $reason, $id]);
        $this->retry->enqueue('hotspot_disable', ['voucher_id' => $id, 'reason' => $reason]);
        $this->audit->record('system', $actorId, 'voucher.revoked', 'voucher', (string) $id, ['reason' => $reason]);

        return true;
    }

    /** @return list<array<string,mixed>> */
    public function timeSeries(int $days = 14): array
    {
        $days = max(1, min(90, $days));
        $from = Time::now() - ($days * 86400);
        $stmt = $this->db->prepare(
            "SELECT substr(date(created_at, 'unixepoch', 'localtime'), 1, 10) AS day,
                    COUNT(*) AS total,
                    SUM(CASE WHEN source = 'captive_portal' THEN 1 ELSE 0 END) AS captive
             FROM vouchers WHERE created_at >= ?
             GROUP BY day ORDER BY day"
        );
        $stmt->execute([$from]);

        return $stmt->fetchAll();
    }

    public function generatePassword(int $length = 8): string
    {
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= self::PASSWORD_ALPHABET[random_int(0, strlen(self::PASSWORD_ALPHABET) - 1)];
        }

        return $out;
    }

    private function uniquePassword(int $length): string
    {
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $password = $this->generatePassword($length);
            $stmt = $this->db->prepare('SELECT 1 FROM vouchers WHERE code = ? OR password = ?');
            $stmt->execute([$password, $password]);
            if (!$stmt->fetchColumn()) {
                return $password;
            }
        }

        throw new RuntimeException('Tidak dapat membuat password unik.');
    }

    private function uniqueCode(): string
    {
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $code = 'BLS' . $this->generatePassword(6);
            $stmt = $this->db->prepare('SELECT 1 FROM vouchers WHERE code = ?');
            $stmt->execute([$code]);
            if (!$stmt->fetchColumn()) {
                return $code;
            }
        }

        throw new RuntimeException('Tidak dapat membuat kode voucher unik.');
    }

    /**
     * @return array{mode:string,value:int,unit:string,expires_at:?int}
     */
    private function durationFor(array $package, string $source): array
    {
        $mode = (string) ($package['duration_mode'] ?? 'uptime');
        if ($source === 'captive_portal' && $mode === 'uptime' && empty($package['duration_value'])) {
            $mode = 'calendar';
        }

        if ($mode === 'calendar') {
            $days = (int) ($package['calendar_days'] ?? 0);
            if ($days < 1) {
                $days = $this->settings->int('wifi.calendar_days_default', 1);
            }
            $expires = strtotime('+' . max(1, $days) . ' days');
            if ($expires === false) {
                $expires = Time::now() + (max(1, $days) * 86400);
            }

            return [
                'mode' => 'calendar',
                'value' => max(0, (int) ($package['duration_value'] ?? 0)),
                'unit' => (string) ($package['duration_unit'] ?? 'days'),
                'expires_at' => $expires,
            ];
        }

        return [
            'mode' => 'uptime',
            'value' => max(1, (int) $package['duration_value']),
            'unit' => (string) $package['duration_unit'],
            'expires_at' => null,
        ];
    }
}
