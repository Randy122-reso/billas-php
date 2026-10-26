<?php

declare(strict_types=1);

namespace Billas\Domain;

use Billas\Support\Crypto;
use Billas\Support\Settings;
use Billas\Support\Time;
use PDO;
use RuntimeException;

/**
 * Fitur E: captive portal sessions. Identity is client_ip + client_mac + SSID +
 * router, signed with SESSION_SECRET; no customer login is required.
 */
final class CaptivePortalService
{
    public function __construct(
        private readonly PDO $db,
        private readonly Crypto $crypto,
        private readonly Settings $settings,
    ) {
    }

    /**
     * @return array{session_token:string,expires_at:int,session_id:int,reused:bool}
     */
    public function createSession(int $routerId, string $ssid, ?string $clientIp, ?string $clientMac): array
    {
        $now = Time::now();
        if ($clientMac !== null) {
            $stmt = $this->db->prepare(
                "SELECT * FROM captive_portal_sessions
                 WHERE status = 'pending' AND client_mac = ? AND ssid = ? AND router_id = ?
                   AND expires_at > ? AND created_at >= ?
                 ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute([$clientMac, $ssid, $routerId, $now, $now - 600]);
            $existing = $stmt->fetch();
            if ($existing) {
                return [
                    'session_token' => (string) $existing['session_token'],
                    'expires_at' => (int) $existing['expires_at'],
                    'session_id' => (int) $existing['id'],
                    'reused' => true,
                ];
            }
        }

        $ttl = max(300, $this->settings->int('wifi.session_token_ttl', 1800));
        $expiresAt = $now + $ttl;
        $payload = implode('|', [$routerId, $ssid, $clientIp ?? '', $clientMac ?? '', $now]);
        $token = $this->crypto->hmac($payload);

        try {
            $stmt = $this->db->prepare(
                'INSERT INTO captive_portal_sessions
                 (session_token, router_id, ssid, client_ip, client_mac, status, created_at, updated_at, expires_at)
                 VALUES (?, ?, ?, ?, ?, "pending", ?, ?, ?)'
            );
            $stmt->execute([$token, $routerId, $ssid, $clientIp, $clientMac, $now, $now, $expiresAt]);
            $id = (int) $this->db->lastInsertId();
        } catch (\Throwable $e) {
            $stmt = $this->db->prepare('SELECT * FROM captive_portal_sessions WHERE session_token = ?');
            $stmt->execute([$token]);
            $existing = $stmt->fetch();
            if (!$existing) {
                throw $e;
            }
            $id = (int) $existing['id'];
            $expiresAt = (int) $existing['expires_at'];
        }

        return [
            'session_token' => $token,
            'expires_at' => $expiresAt,
            'session_id' => $id,
            'reused' => false,
        ];
    }

    /** @return array<string,mixed>|null */
    public function validateSessionToken(?string $token): ?array
    {
        if ($token === null || $token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $stmt = $this->db->prepare('SELECT * FROM captive_portal_sessions WHERE session_token = ?');
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        if (!$row || $row['status'] !== 'pending' || (int) $row['expires_at'] < Time::now()) {
            return null;
        }

        $payload = implode('|', [
            (int) $row['router_id'],
            (string) $row['ssid'],
            (string) ($row['client_ip'] ?? ''),
            (string) ($row['client_mac'] ?? ''),
            (int) $row['created_at'],
        ]);
        if (!$this->crypto->verifyHmac($payload, (string) $row['session_token'])) {
            return null;
        }

        return $row;
    }

    public function markPaid(string $token, string $orderId, int $voucherId): void
    {
        $this->db->prepare(
            'UPDATE captive_portal_sessions
             SET status = "paid", order_id = ?, voucher_id = ?, updated_at = ?
             WHERE session_token = ?'
        )->execute([$orderId, $voucherId, Time::now(), $token]);
    }

    public function markPaidByOrder(string $orderId, int $voucherId): void
    {
        $this->db->prepare(
            'UPDATE captive_portal_sessions
             SET status = "paid", voucher_id = ?, updated_at = ?
             WHERE order_id = ? AND status = "pending"'
        )->execute([$voucherId, Time::now(), $orderId]);
    }

    public function cancelByOrder(string $orderId): void
    {
        $this->db->prepare(
            "UPDATE captive_portal_sessions SET status = 'cancelled', updated_at = ? WHERE order_id = ? AND status = 'pending'"
        )->execute([Time::now(), $orderId]);
    }

    /** @return array{expired:int} */
    public function expireSessions(): array
    {
        $now = Time::now();
        $stmt = $this->db->prepare(
            "UPDATE captive_portal_sessions SET status = 'expired', updated_at = ?
             WHERE status = 'pending' AND expires_at < ?"
        );
        $stmt->execute([$now, $now]);

        return ['expired' => $stmt->rowCount()];
    }

    /** @return array<string,mixed>|null */
    public function findByOrderId(string $orderId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM captive_portal_sessions WHERE order_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$orderId]);

        return $stmt->fetch() ?: null;
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM captive_portal_sessions WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    /** @return list<array<string,mixed>> */
    public function listAdmin(string $status = '', string $search = ''): array
    {
        $sql = 'SELECT s.*, p.name AS package_name, o.status AS order_status, o.amount,
                       v.code AS voucher_code, v.revoked AS voucher_revoked, v.expires_at AS voucher_expires_at
                FROM captive_portal_sessions s
                LEFT JOIN packages p ON p.id = s.package_id
                LEFT JOIN orders o ON o.id = s.order_id
                LEFT JOIN vouchers v ON v.id = s.voucher_id
                WHERE 1 = 1';
        $params = [];
        if (in_array($status, ['pending', 'paid', 'expired', 'cancelled', 'revoked'], true)) {
            $sql .= ' AND s.status = ?';
            $params[] = $status;
        }
        if ($search !== '') {
            $sql .= ' AND (s.client_ip LIKE ? OR s.client_mac LIKE ? OR s.ssid LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like);
        }
        $sql .= ' ORDER BY s.id DESC LIMIT 500';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @return array<string,int> */
    public function stats(): array
    {
        $rows = $this->db->query('SELECT status, COUNT(*) AS total FROM captive_portal_sessions GROUP BY status')->fetchAll();
        $result = ['pending' => 0, 'paid' => 0, 'expired' => 0, 'cancelled' => 0, 'revoked' => 0];
        foreach ($rows as $row) {
            $result[(string) $row['status']] = (int) $row['total'];
        }

        return $result;
    }

    /** @return list<array<string,mixed>> */
    public function recent(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->db->query(
            "SELECT s.*, p.name AS package_name FROM captive_portal_sessions s
             LEFT JOIN packages p ON p.id = s.package_id
             ORDER BY s.id DESC LIMIT {$limit}"
        );

        return $stmt->fetchAll();
    }
}
