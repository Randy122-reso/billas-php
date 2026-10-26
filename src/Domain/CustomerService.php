<?php

declare(strict_types=1);

namespace Billas\Domain;

use Billas\Support\Settings;
use Billas\Support\Time;
use InvalidArgumentException;
use PDO;

final class CustomerService
{
    public function __construct(
        private readonly PDO $db,
        private readonly Settings $settings,
        private readonly AuditService $audit,
        private readonly RetryService $retry,
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function all(?string $status = null, string $search = ''): array
    {
        $sql = 'SELECT c.*, p.name AS package_name, p.price AS package_price, r.name AS router_name,
                       (SELECT a.external_ref FROM customer_payment_accounts a
                        WHERE a.customer_id = c.id AND a.account_type = "qris" AND a.active = 1
                        ORDER BY a.id LIMIT 1) AS qris_unique_ref
                FROM customers c
                LEFT JOIN packages p ON p.id = c.package_id
                LEFT JOIN routers r ON r.id = c.router_id
                WHERE 1 = 1';
        $params = [];
        if (in_array($status, ['active', 'isolir', 'inactive'], true)) {
            $sql .= ' AND c.status = ?';
            $params[] = $status;
        }
        if ($search !== '') {
            $sql .= ' AND (c.code LIKE ? OR c.name LIKE ? OR c.phone LIKE ? OR c.pppoe_username LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like);
        }
        $sql .= ' ORDER BY c.id DESC LIMIT 300';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT c.*, p.name AS package_name, p.price AS package_price, p.type AS package_type,
                    r.name AS router_name
             FROM customers c
             LEFT JOIN packages p ON p.id = c.package_id
             LEFT JOIN routers r ON r.id = c.router_id
             WHERE c.id = ?'
        );
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findByCodeOrPhone(string $identifier): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM customers WHERE code = ? OR phone = ? LIMIT 1');
        $stmt->execute([$identifier, $identifier]);

        return $stmt->fetch() ?: null;
    }

    /** @return array<string,mixed>|null */
    public function authenticate(string $identifier, string $password): ?array
    {
        $customer = $this->findByCodeOrPhone($identifier);
        if ($customer === null || $customer['status'] === 'inactive') {
            return null;
        }
        if (empty($customer['portal_password_hash']) || !password_verify($password, (string) $customer['portal_password_hash'])) {
            return null;
        }

        return $customer;
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        $code = trim((string) ($data['code'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Nama customer wajib diisi.');
        }
        if ($code === '') {
            $code = $this->newCode();
        }
        $this->assertUnique('code', $code);
        $packageId = $this->nullableInt($data['package_id'] ?? null);
        $routerId = $this->nullableInt($data['router_id'] ?? null);
        $status = in_array($data['status'] ?? '', ['active', 'isolir', 'inactive'], true) ? (string) $data['status'] : 'active';
        $password = (string) ($data['portal_password'] ?? '');
        $now = Time::now();

        $stmt = $this->db->prepare(
            'INSERT INTO customers
             (code, name, phone, email, pppoe_username, pppoe_password, package_id, router_id,
              portal_password_hash, status, paid_until, service_guaranteed_until, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $code,
            $name,
            $this->nullableString($data['phone'] ?? null),
            $this->nullableString($data['email'] ?? null),
            $this->nullableString($data['pppoe_username'] ?? null),
            $this->nullableString($data['pppoe_password'] ?? null),
            $packageId,
            $routerId,
            $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : null,
            $status,
            $this->nullableString($data['paid_until'] ?? null),
            $this->nullableString($data['service_guaranteed_until'] ?? null),
            $now,
        ]);
        $id = (int) $this->db->lastInsertId();
        $this->audit->record('admin', (string) ($data['_actor'] ?? 'admin'), 'customer.created', 'customer', (string) $id, [
            'code' => $code,
            'package_id' => $packageId,
        ]);

        return $id;
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $customer = $this->find($id);
        if ($customer === null) {
            throw new InvalidArgumentException('Customer tidak ditemukan.');
        }
        $name = trim((string) ($data['name'] ?? $customer['name']));
        if ($name === '') {
            throw new InvalidArgumentException('Nama customer wajib diisi.');
        }
        $code = trim((string) ($data['code'] ?? $customer['code']));
        if ($code !== $customer['code']) {
            $this->assertUnique('code', $code, $id);
        }
        $status = in_array($data['status'] ?? '', ['active', 'isolir', 'inactive'], true)
            ? (string) $data['status'] : (string) $customer['status'];

        $sql = 'UPDATE customers SET code = ?, name = ?, phone = ?, email = ?, pppoe_username = ?,
                pppoe_password = ?, package_id = ?, router_id = ?, status = ?, paid_until = ?,
                service_guaranteed_until = ?';
        $params = [
            $code,
            $name,
            $this->nullableString($data['phone'] ?? null),
            $this->nullableString($data['email'] ?? null),
            $this->nullableString($data['pppoe_username'] ?? null),
            $this->nullableString($data['pppoe_password'] ?? null),
            $this->nullableInt($data['package_id'] ?? null),
            $this->nullableInt($data['router_id'] ?? null),
            $status,
            $this->nullableString($data['paid_until'] ?? $customer['paid_until']),
            $this->nullableString($data['service_guaranteed_until'] ?? $customer['service_guaranteed_until']),
        ];

        $password = (string) ($data['portal_password'] ?? '');
        if ($password !== '') {
            $sql .= ', portal_password_hash = ?';
            $params[] = password_hash($password, PASSWORD_DEFAULT);
        }
        $sql .= ' WHERE id = ?';
        $params[] = $id;

        $this->db->prepare($sql)->execute($params);
        $this->audit->record('admin', (string) ($data['_actor'] ?? 'admin'), 'customer.updated', 'customer', (string) $id, [
            'status' => $status,
        ]);
    }

    public function setStatus(int $id, string $status): void
    {
        if (!in_array($status, ['active', 'isolir', 'inactive'], true)) {
            throw new InvalidArgumentException('Status customer tidak valid.');
        }
        $this->db->prepare('UPDATE customers SET status = ? WHERE id = ?')->execute([$status, $id]);
        $this->audit->record('admin', 'admin', 'customer.status', 'customer', (string) $id, ['status' => $status]);
    }

    /**
     * Fitur C/D: isolir based on paid_until + unpaid invoice grace, while
     * respecting service_guaranteed_until.
     *
     * @return array{isolated:list<int>,skipped_guaranteed:int}
     */
    public function isolirDue(int $graceDays = 3): array
    {
        $today = Time::today();
        $cutoff = Time::addDays($today, -max(0, $graceDays));

        $skipped = (int) $this->db->query(
            "SELECT COUNT(*) FROM customers
             WHERE status = 'active'
               AND paid_until IS NOT NULL AND paid_until < '{$today}'
               AND service_guaranteed_until IS NOT NULL AND service_guaranteed_until >= '{$today}'"
        )->fetchColumn();

        $stmt = $this->db->prepare(
            "SELECT c.* FROM customers c
             WHERE c.status = 'active'
               AND (c.paid_until IS NULL OR c.paid_until < ?)
               AND (c.service_guaranteed_until IS NULL OR c.service_guaranteed_until < ?)
               AND EXISTS (
                 SELECT 1 FROM invoices i
                 WHERE i.customer_id = c.id AND i.status = 'unpaid' AND i.due_date < ?
               )"
        );
        $stmt->execute([$today, $today, $cutoff]);
        $rows = $stmt->fetchAll();

        $isolated = [];
        foreach ($rows as $customer) {
            $this->db->prepare("UPDATE customers SET status = 'isolir' WHERE id = ?")->execute([$customer['id']]);
            $this->audit->record('system', 'cron', 'customer.isolir', 'customer', (string) $customer['id'], [
                'paid_until' => $customer['paid_until'],
                'service_guaranteed_until' => $customer['service_guaranteed_until'],
            ]);
            $this->retry->enqueue('pppoe_disable', ['customer_id' => (int) $customer['id']]);
            $this->retry->enqueue('pppoe_kick', ['customer_id' => (int) $customer['id']]);
            $isolated[] = (int) $customer['id'];
        }

        return ['isolated' => $isolated, 'skipped_guaranteed' => $skipped];
    }

    public function reactivate(int $id): void
    {
        $this->db->prepare("UPDATE customers SET status = 'active' WHERE id = ?")->execute([$id]);
        $this->retry->enqueue('pppoe_enable', ['customer_id' => $id]);
        $this->retry->enqueue('pppoe_kick', ['customer_id' => $id]);
        $this->audit->record('admin', 'admin', 'customer.reactivated', 'customer', (string) $id);
    }

    /** @return array<string,int> */
    public function stats(): array
    {
        $rows = $this->db->query('SELECT status, COUNT(*) AS total FROM customers GROUP BY status')->fetchAll();
        $result = ['active' => 0, 'isolir' => 0, 'inactive' => 0];
        foreach ($rows as $row) {
            $result[(string) $row['status']] = (int) $row['total'];
        }

        return $result;
    }

    private function newCode(): string
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $code = 'CUS-' . strtoupper(bin2hex(random_bytes(3)));
            $stmt = $this->db->prepare('SELECT 1 FROM customers WHERE code = ?');
            $stmt->execute([$code]);
            if (!$stmt->fetchColumn()) {
                return $code;
            }
        }

        throw new InvalidArgumentException('Tidak dapat membuat kode customer.');
    }

    private function assertUnique(string $column, string $value, ?int $exceptId = null): void
    {
        $sql = "SELECT 1 FROM customers WHERE {$column} = ?";
        $params = [$value];
        if ($exceptId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $exceptId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        if ($stmt->fetchColumn()) {
            throw new InvalidArgumentException('Nilai ' . $column . ' sudah digunakan.');
        }
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }
}
