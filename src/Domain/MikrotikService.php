<?php

declare(strict_types=1);

namespace Billas\Domain;

use Billas\Support\Time;
use PDO;
use RouterOS\Query;
use RuntimeException;

/**
 * High-level MikroTik operations. Calendar vouchers are disabled by cron; uptime
 * vouchers additionally receive limit-uptime on the router.
 */
final class MikrotikService
{
    public function __construct(
        private readonly PDO $db,
        private readonly RouterService $routers,
        private readonly MikrotikClient $client,
        private readonly AuditService $audit,
    ) {
    }

    public function pushVoucherById(int $voucherId): void
    {
        $stmt = $this->db->prepare('SELECT * FROM vouchers WHERE id = ?');
        $stmt->execute([$voucherId]);
        $voucher = $stmt->fetch();
        if (!$voucher) {
            throw new RuntimeException('Voucher tidak ditemukan: ' . $voucherId);
        }
        if ((int) $voucher['revoked'] === 1) {
            return;
        }

        $routerId = $voucher['router_id'] !== null ? (int) $voucher['router_id'] : $this->routers->defaultId();
        if ($routerId === null) {
            throw new RuntimeException('Tidak ada router aktif untuk push voucher.');
        }

        $attributes = [
            'name' => (string) $voucher['code'],
            'password' => (string) $voucher['password'],
            'profile' => (string) $voucher['mikrotik_profile'],
            'comment' => 'Billas ' . $voucher['source'] . ' ' . date('Y-m-d H:i', (int) $voucher['created_at']),
        ];
        if ($voucher['duration_mode'] === 'uptime') {
            $attributes['limit-uptime'] = $this->duration((int) $voucher['duration_value'], (string) $voucher['duration_unit']);
        }
        if ($voucher['expires_at'] !== null) {
            $attributes['comment'] .= ' exp:' . date('Y-m-d H:i', (int) $voucher['expires_at']);
        }

        try {
            if ($this->client->isFake()) {
                $this->client->logFake('hotspot user add', [
                    'router_id' => $routerId,
                    'name' => $attributes['name'],
                    'profile' => $attributes['profile'],
                    'source' => $voucher['source'],
                ]);
            } else {
                $connection = $this->routers->connection($routerId);
                $existing = $this->client->execute(
                    $connection,
                    (new Query('/ip/hotspot/user/print'))->where('name', (string) $voucher['code'])
                );
                if ($existing !== []) {
                    $query = (new Query('/ip/hotspot/user/set'))->add('=.id=' . $existing[0]['.id']);
                    foreach ($attributes as $key => $value) {
                        $query->add('=' . $key . '=' . $value);
                    }
                    $this->client->execute($connection, $query);
                } else {
                    $query = new Query('/ip/hotspot/user/add');
                    foreach ($attributes as $key => $value) {
                        $query->add('=' . $key . '=' . $value);
                    }
                    $this->client->execute($connection, $query);
                }
            }

            $this->db->prepare('UPDATE vouchers SET pushed_to_router = 1, push_error = NULL, router_id = ? WHERE id = ?')
                ->execute([$routerId, $voucherId]);
            $this->audit->record('system', 'worker', 'voucher.pushed', 'voucher', (string) $voucherId, ['router_id' => $routerId]);
        } catch (\Throwable $e) {
            $this->db->prepare('UPDATE vouchers SET push_error = ?, router_id = ? WHERE id = ?')
                ->execute([mb_substr($e->getMessage(), 0, 2000), $routerId, $voucherId]);
            throw $e;
        }
    }

    public function disableVoucherById(int $voucherId, string $reason = 'expired'): void
    {
        $stmt = $this->db->prepare('SELECT * FROM vouchers WHERE id = ?');
        $stmt->execute([$voucherId]);
        $voucher = $stmt->fetch();
        if (!$voucher) {
            return;
        }
        $routerId = $voucher['router_id'] !== null ? (int) $voucher['router_id'] : $this->routers->defaultId();
        if ($routerId === null) {
            throw new RuntimeException('Tidak ada router aktif untuk disable voucher.');
        }

        try {
            if ($this->client->isFake()) {
                $this->client->logFake('hotspot user disable', [
                    'router_id' => $routerId,
                    'name' => $voucher['code'],
                    'reason' => $reason,
                ]);
            } else {
                $connection = $this->routers->connection($routerId);
                $existing = $this->client->execute(
                    $connection,
                    (new Query('/ip/hotspot/user/print'))->where('name', (string) $voucher['code'])
                );
                if ($existing !== []) {
                    $query = (new Query('/ip/hotspot/user/set'))
                        ->add('=.id=' . $existing[0]['.id'])
                        ->add('=disabled=yes');
                    $this->client->execute($connection, $query);
                }
            }
            $this->audit->record('system', 'worker', 'voucher.disabled', 'voucher', (string) $voucherId, ['reason' => $reason]);
        } catch (\Throwable $e) {
            $this->db->prepare('UPDATE vouchers SET push_error = ? WHERE id = ?')
                ->execute([mb_substr($e->getMessage(), 0, 2000), $voucherId]);
            throw $e;
        }
    }

    public function enablePppoe(int $customerId): void
    {
        $this->setPppoeStatus($customerId, false);
        $this->audit->record('system', 'worker', 'pppoe.enabled', 'customer', (string) $customerId);
    }

    public function disablePppoe(int $customerId): void
    {
        $this->setPppoeStatus($customerId, true);
        $this->audit->record('system', 'worker', 'pppoe.disabled', 'customer', (string) $customerId);
    }

    public function kickPppoe(int $customerId): void
    {
        $customer = $this->customer($customerId);
        $username = (string) ($customer['pppoe_username'] ?? '');
        if ($username === '') {
            return;
        }
        $routerId = $customer['router_id'] !== null ? (int) $customer['router_id'] : $this->routers->defaultId();
        if ($routerId === null) {
            throw new RuntimeException('Tidak ada router aktif untuk kick PPPoE.');
        }
        if ($this->client->isFake()) {
            $this->client->logFake('pppoe kick', ['router_id' => $routerId, 'username' => $username]);

            return;
        }
        $connection = $this->routers->connection($routerId);
        $rows = $this->client->execute($connection, (new Query('/ppp/active/print'))->where('name', $username));
        foreach ($rows as $row) {
            if (isset($row['.id'])) {
                $this->client->execute($connection, (new Query('/ppp/active/remove'))->add('=.id=' . $row['.id']));
            }
        }
    }

    public function testRouter(int $routerId): string
    {
        if ($this->client->isFake()) {
            $this->client->logFake('router identity', ['router_id' => $routerId]);

            return 'fake-router-' . $routerId;
        }
        $rows = $this->client->execute($this->routers->connection($routerId), new Query('/system/identity/print'));

        return (string) ($rows[0]['name'] ?? 'unknown');
    }

    private function setPppoeStatus(int $customerId, bool $disabled): void
    {
        $customer = $this->customer($customerId);
        $username = (string) ($customer['pppoe_username'] ?? '');
        if ($username === '') {
            return;
        }
        $routerId = $customer['router_id'] !== null ? (int) $customer['router_id'] : $this->routers->defaultId();
        if ($routerId === null) {
            throw new RuntimeException('Tidak ada router aktif untuk PPPoE.');
        }
        if ($this->client->isFake()) {
            $this->client->logFake('pppoe ' . ($disabled ? 'disable' : 'enable'), ['router_id' => $routerId, 'username' => $username]);

            return;
        }
        $connection = $this->routers->connection($routerId);
        $rows = $this->client->execute($connection, (new Query('/ppp/secret/print'))->where('name', $username));
        if ($rows === []) {
            throw new RuntimeException('PPPoE secret tidak ditemukan: ' . $username);
        }
        $this->client->execute(
            $connection,
            (new Query('/ppp/secret/set'))
                ->add('=.id=' . $rows[0]['.id'])
                ->add('=disabled=' . ($disabled ? 'yes' : 'no'))
        );
    }

    /** @return array<string,mixed> */
    private function customer(int $customerId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM customers WHERE id = ?');
        $stmt->execute([$customerId]);
        $customer = $stmt->fetch();
        if (!$customer) {
            throw new RuntimeException('Customer tidak ditemukan: ' . $customerId);
        }

        return $customer;
    }

    private function duration(int $value, string $unit): string
    {
        return match ($unit) {
            'minutes' => $value . 'm',
            'hours' => $value . 'h',
            'days' => $value . 'd',
            default => $value . 'd',
        };
    }
}
