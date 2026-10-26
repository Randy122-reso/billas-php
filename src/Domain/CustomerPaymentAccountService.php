<?php

declare(strict_types=1);

namespace Billas\Domain;

use Billas\Domain\PaymentGateway\GatewayFactory;
use Billas\Support\Settings;
use Billas\Support\Time;
use PDO;
use RuntimeException;
use Throwable;

final class CustomerPaymentAccountService
{
    public function __construct(
        private readonly PDO $db,
        private readonly Settings $settings,
        private readonly GatewayFactory $gateways,
        private readonly AuditService $audit,
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function allForCustomer(int $customerId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM customer_payment_accounts WHERE customer_id = ? ORDER BY account_type, id'
        );
        $stmt->execute([$customerId]);

        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function findActive(int $customerId, string $gateway, string $accountType): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM customer_payment_accounts
             WHERE customer_id = ? AND gateway = ? AND account_type = ? AND active = 1 LIMIT 1'
        );
        $stmt->execute([$customerId, $gateway, $accountType]);

        return $stmt->fetch() ?: null;
    }

    /** @return array<string,mixed>|null */
    public function anyActiveQris(int $customerId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM customer_payment_accounts
             WHERE customer_id = ? AND account_type = 'qris' AND active = 1 ORDER BY id LIMIT 1"
        );
        $stmt->execute([$customerId]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Fitur B: persistent VA/QRIS account.
     *
     * @return array<string,mixed>
     */
    public function getOrCreate(int $customerId, string $gateway, string $accountType = 'va'): array
    {
        $existing = $this->findActive($customerId, $gateway, $accountType);
        if ($existing !== null) {
            return $existing;
        }
        $customer = $this->customer($customerId);
        $provider = $this->gateways->make($gateway);
        if (!$provider->supportsPersistent($accountType)) {
            throw new RuntimeException('Gateway ' . $gateway . ' tidak mendukung akun ' . $accountType . '.');
        }

        $result = $provider->createPersistentAccount([
            'customer' => $customer,
            'account_type' => $accountType,
            'publicBase' => (string) ($_ENV['APP_URL'] ?? ''),
        ]);

        return $this->insert($customerId, $gateway, $accountType, $result, true);
    }

    /**
     * Fitur D: QRIS unik per customer, idempotent.
     *
     * @return array<string,mixed>
     */
    public function getOrCreateQris(int $customerId, ?string $gateway = null): array
    {
        $existing = $this->anyActiveQris($customerId);
        if ($existing !== null) {
            return $existing;
        }

        $gateway = $this->pickQrisGateway($gateway);
        $customer = $this->customer($customerId);
        $provider = $this->gateways->make($gateway);
        if (!$provider->supportsPersistent('qris')) {
            throw new RuntimeException('Gateway ' . $gateway . ' tidak mendukung QRIS unik.');
        }
        $result = $provider->createPersistentAccount([
            'customer' => $customer,
            'account_type' => 'qris',
            'publicBase' => (string) ($_ENV['APP_URL'] ?? ''),
        ]);

        return $this->insert($customerId, $gateway, 'qris', $result, true);
    }

    public function deactivate(int $id): void
    {
        $stmt = $this->db->prepare('SELECT * FROM customer_payment_accounts WHERE id = ?');
        $stmt->execute([$id]);
        $account = $stmt->fetch();
        if (!$account) {
            return;
        }
        $this->db->prepare('UPDATE customer_payment_accounts SET active = 0, updated_at = ? WHERE id = ?')
            ->execute([Time::now(), $id]);
        $this->audit->record('admin', 'admin', 'qris_unique.deactivated', 'payment_account', (string) $id, [
            'customer_id' => $account['customer_id'],
            'gateway' => $account['gateway'],
        ]);
    }

    /** @return array<string,mixed>|null */
    public function resolveByExternalRef(string $externalRef): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT a.*, c.name AS customer_name, c.code AS customer_code
             FROM customer_payment_accounts a
             JOIN customers c ON c.id = a.customer_id
             WHERE (a.external_ref = ? OR a.qris_unique_ref = ?) LIMIT 1'
        );
        $stmt->execute([$externalRef, $externalRef]);

        return $stmt->fetch() ?: null;
    }

    /** @param array<string,mixed> $result */
    private function insert(int $customerId, string $gateway, string $accountType, array $result, bool $audit): array
    {
        $now = Time::now();
        $externalRef = (string) $result['external_ref'];
        $qrisUniqueRef = $accountType === 'qris' ? $externalRef : null;
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO customer_payment_accounts
                 (customer_id, gateway, account_type, external_ref, qris_unique_ref, qr_string, payload, active, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)'
            );
            $stmt->execute([
                $customerId,
                $gateway,
                $accountType,
                $externalRef,
                $qrisUniqueRef,
                $result['qr_string'] ?? null,
                json_encode($result['payload'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $now,
                $now,
            ]);
            $id = (int) $this->db->lastInsertId();
        } catch (Throwable $e) {
            // Handle concurrent auto-create requests gracefully.
            $existing = $this->findActive($customerId, $gateway, $accountType);
            if ($existing !== null) {
                return $existing;
            }
            throw $e;
        }

        if ($audit) {
            $this->audit->record(
                'system',
                'system',
                $accountType === 'qris' ? 'qris_unique.created' : 'payment_account.created',
                'payment_account',
                (string) $id,
                ['gateway' => $gateway, 'customer_id' => $customerId]
            );
        }

        $stmt = $this->db->prepare('SELECT * FROM customer_payment_accounts WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch();
    }

    private function pickQrisGateway(?string $preferred): string
    {
        if ($preferred !== null && $preferred !== '' && $this->gateways->configured($preferred)) {
            $provider = $this->gateways->make($preferred);
            if ($provider->supportsPersistent('qris')) {
                return $preferred;
            }
        }

        $allowed = $this->settings->csv('pppoe_qris.providers', 'midtrans,xendit,duitku');
        foreach ($allowed as $name) {
            if ($this->gateways->configured($name) && $this->gateways->make($name)->supportsPersistent('qris')) {
                return $name;
            }
        }
        if ($this->gateways->configured('qris_static')) {
            return 'qris_static';
        }

        throw new RuntimeException('Tidak ada gateway QRIS unik yang terkonfigurasi.');
    }

    /** @return array<string,mixed> */
    private function customer(int $customerId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM customers WHERE id = ?');
        $stmt->execute([$customerId]);
        $customer = $stmt->fetch();
        if (!$customer) {
            throw new RuntimeException('Customer tidak ditemukan.');
        }

        return $customer;
    }
}
