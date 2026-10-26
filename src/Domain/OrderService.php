<?php

declare(strict_types=1);

namespace Billas\Domain;

use Billas\Support\Crypto;
use Billas\Support\Settings;
use Billas\Support\Time;
use PDO;
use RuntimeException;

final class OrderService
{
    public function __construct(
        private readonly PDO $db,
        private readonly Settings $settings,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * Create a web portal order after a provider has successfully produced a
     * payment. The unique amount is already included by PaymentService.
     *
     * @param array<string,mixed> $payment
     * @return array<string,mixed>
     */
    public function createWebOrder(array $package, string $gateway, int $amount, array $payment, array $context = [], ?string $merchantRef = null): array
    {
        return $this->create($package, $gateway, $amount, $payment, [
            'source' => 'web_portal',
            'ssid' => null,
            'client_ip' => $context['client_ip'] ?? null,
            'client_mac' => $context['client_mac'] ?? null,
        ], $merchantRef);
    }

    /**
     * Fitur E: create an order linked to a captive portal session.
     *
     * @param array<string,mixed> $payment
     * @return array<string,mixed>
     */
    public function createCaptiveOrder(array $package, string $gateway, int $amount, array $payment, array $ctx, ?string $merchantRef = null): array
    {
        if (($package['type'] ?? '') !== 'hotspot' || (int) ($package['available_on_captive'] ?? 0) !== 1) {
            throw new RuntimeException('Paket tidak tersedia di captive portal.');
        }
        $mac = $ctx['client_mac'] ?? null;
        $maxPending = $this->settings->int('wifi.max_pending_orders_per_mac', 2);
        if ($mac !== null && $mac !== '' && $maxPending > 0) {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM orders
                 WHERE source = 'captive_portal' AND client_mac = ?
                   AND status = 'pending' AND expires_at > ?"
            );
            $stmt->execute([$mac, Time::now()]);
            if ((int) $stmt->fetchColumn() >= $maxPending) {
                throw new RuntimeException('Terlalu banyak order pending untuk perangkat ini. Selesaikan atau tunggu order sebelumnya kedaluwarsa.');
            }
        }

        $order = $this->create($package, $gateway, $amount, $payment, [
            'source' => 'captive_portal',
            'ssid' => $ctx['ssid'] ?? null,
            'client_ip' => $ctx['client_ip'] ?? null,
            'client_mac' => $mac,
        ], $merchantRef);

        if (!empty($ctx['session_token'])) {
            $this->db->prepare(
                'UPDATE captive_portal_sessions SET order_id = ?, package_id = ?, updated_at = ? WHERE session_token = ?'
            )->execute([$order['id'], $package['id'], Time::now(), $ctx['session_token']]);
        }

        $this->audit->record('system', 'captive', 'captive.order_created', 'order', (string) $order['id'], [
            'ssid' => $ctx['ssid'] ?? null,
            'client_mac' => $mac,
            'package_id' => $package['id'],
        ], $ctx['client_ip'] ?? null);

        return $order;
    }

    /**
     * @param array<string,mixed> $payment
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function create(array $package, string $gateway, int $amount, array $payment, array $context, ?string $merchantRef = null): array
    {
        if ((int) $amount < (int) $package['price']) {
            throw new RuntimeException('Nominal order lebih kecil dari harga paket.');
        }
        $id = Crypto::uuid();
        $merchantRef = $merchantRef ?? $this->merchantRef();
        $now = Time::now();
        $expiresAt = (int) ($payment['expires_at'] ?? ($now + $this->settings->int('payment.order_ttl', 900)));
        if ($expiresAt <= $now) {
            $expiresAt = $now + 900;
        }

        $payload = $payment['payload'] ?? [];
        if (is_string($payload)) {
            $payloadString = $payload;
        } else {
            $payloadString = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $stmt = $this->db->prepare(
            'INSERT INTO orders
             (id, package_id, amount, gateway, status, merchant_ref, gateway_reference, gateway_qr_string,
              gateway_payload, expires_at, source, ssid, client_ip, client_mac, created_at)
             VALUES (?, ?, ?, ?, "pending", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            (int) $package['id'],
            $amount,
            $gateway,
            $merchantRef,
            $payment['reference'] ?? null,
            $payment['qr_string'] ?? null,
            $payloadString,
            $expiresAt,
            $context['source'] ?? 'web_portal',
            $context['ssid'] ?? null,
            $context['client_ip'] ?? null,
            $context['client_mac'] ?? null,
            $now,
        ]);

        $order = $this->find($id);
        if ($order === null) {
            throw new RuntimeException('Gagal membuat order.');
        }

        return $order;
    }

    public function markPaid(string $orderId, ?string $gatewayReference = null, ?int $paidAt = null): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE orders SET status = 'paid', paid_at = ?, gateway_reference = COALESCE(?, gateway_reference)
             WHERE id = ? AND status != 'paid'"
        );
        $stmt->execute([$paidAt ?? Time::now(), $gatewayReference, $orderId]);

        return $stmt->rowCount() > 0;
    }

    public function markProcessing(string $orderId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE orders SET status = 'processing' WHERE id = ? AND status = 'pending'"
        );
        $stmt->execute([$orderId]);

        return $stmt->rowCount() > 0;
    }

    /** @return array<string,mixed>|null */
    public function find(string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM orders WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findByMerchantRef(string $merchantRef): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM orders WHERE merchant_ref = ?');
        $stmt->execute([$merchantRef]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findWithPackage(string $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT o.*, p.name AS package_name, p.type AS package_type, p.duration_mode,
                    p.duration_value, p.duration_unit, p.calendar_days, p.mikrotik_profile
             FROM orders o JOIN packages p ON p.id = o.package_id WHERE o.id = ?'
        );
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    /** @return list<array<string,mixed>> */
    public function recent(int $limit = 100, string $source = ''): array
    {
        $limit = max(1, min(500, $limit));
        $sql = 'SELECT o.*, p.name AS package_name
                FROM orders o JOIN packages p ON p.id = o.package_id';
        $params = [];
        if (in_array($source, ['web_portal', 'captive_portal'], true)) {
            $sql .= ' WHERE o.source = ?';
            $params[] = $source;
        }
        $sql .= " ORDER BY o.created_at DESC LIMIT {$limit}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function expirePending(): int
    {
        $now = Time::now();
        $stmt = $this->db->prepare(
            "UPDATE orders SET status = 'expired'
             WHERE status = 'pending' AND expires_at <= ?"
        );
        $stmt->execute([$now]);

        return $stmt->rowCount();
    }

    public function pendingCountForMac(string $mac): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM orders
             WHERE source = 'captive_portal' AND client_mac = ? AND status = 'pending' AND expires_at > ?"
        );
        $stmt->execute([$mac, Time::now()]);

        return (int) $stmt->fetchColumn();
    }

    public function merchantRef(): string
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $ref = 'ORD-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
            $stmt = $this->db->prepare('SELECT 1 FROM orders WHERE merchant_ref = ?');
            $stmt->execute([$ref]);
            if (!$stmt->fetchColumn()) {
                return $ref;
            }
        }

        throw new RuntimeException('Tidak dapat membuat merchant reference unik.');
    }
}
