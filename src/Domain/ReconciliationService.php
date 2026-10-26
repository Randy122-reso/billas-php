<?php

declare(strict_types=1);

namespace Billas\Domain;

use Billas\Support\Time;
use PDO;
use RuntimeException;

/**
 * Manual reconciliation for static QRIS. Static QRIS has no webhook, so the
 * operator matches the exact unique nominal against the bank statement and
 * confirms it here. The webhook pipeline is reused for side effects.
 */
final class ReconciliationService
{
    public function __construct(
        private readonly PDO $db,
        private readonly WebhookService $webhooks,
        private readonly AuditService $audit,
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function pending(int $days = 14): array
    {
        $days = max(1, min(90, $days));
        $from = Time::now() - ($days * 86400);
        $rows = [];

        $stmt = $this->db->prepare(
            "SELECT 'order' AS kind, id, merchant_ref, amount, gateway, status, created_at, expires_at,
                    NULL AS invoice_id, NULL AS invoice_no
             FROM orders
             WHERE gateway = 'qris_static' AND status = 'pending' AND created_at >= ?
             ORDER BY created_at DESC LIMIT 200"
        );
        $stmt->execute([$from]);
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = $row;
        }

        $stmt = $this->db->prepare(
            "SELECT 'transaction' AS kind, t.id, t.merchant_ref, t.amount, t.gateway, t.status,
                    t.created_at, t.expires_at, t.invoice_id, i.invoice_no
             FROM payment_transactions t
             LEFT JOIN invoices i ON i.id = t.invoice_id
             WHERE t.gateway = 'qris_static' AND t.status = 'pending' AND t.created_at >= ?
             ORDER BY t.created_at DESC LIMIT 200"
        );
        $stmt->execute([$from]);
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = $row;
        }

        usort($rows, static fn (array $a, array $b): int => (int) $b['created_at'] <=> (int) $a['created_at']);

        return $rows;
    }

    /**
     * Active persistent QRIS accounts. They have no fixed amount, so the
     * operator enters the exact bank-statement amount when confirming.
     *
     * @return list<array<string,mixed>>
     */
    public function qrisAccounts(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $stmt = $this->db->query(
            "SELECT a.id, a.external_ref, a.gateway, a.created_at,
                    c.id AS customer_id, c.code AS customer_code, c.name AS customer_name, c.status AS customer_status,
                    c.paid_until, c.service_guaranteed_until
             FROM customer_payment_accounts a
             JOIN customers c ON c.id = a.customer_id
             WHERE a.account_type = 'qris' AND a.active = 1
             ORDER BY a.id DESC LIMIT {$limit}"
        );

        return $stmt->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function unmatchedWebhooks(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = $this->db->query(
            "SELECT * FROM webhook_events WHERE status = 'unmatched' ORDER BY id DESC LIMIT {$limit}"
        );

        return $stmt->fetchAll();
    }

    /** @return array{pending:int,unmatched:int,preview:list<array<string,mixed>>} */
    public function stats(int $days = 14): array
    {
        $from = Time::now() - (max(1, $days) * 86400);
        $pending = (int) $this->db->query(
            "SELECT COUNT(*) FROM orders WHERE gateway = 'qris_static' AND status = 'pending' AND created_at >= {$from}"
        )->fetchColumn();
        $pending += (int) $this->db->query(
            "SELECT COUNT(*) FROM payment_transactions WHERE gateway = 'qris_static' AND status = 'pending' AND created_at >= {$from}"
        )->fetchColumn();
        $unmatched = (int) $this->db->query("SELECT COUNT(*) FROM webhook_events WHERE status = 'unmatched'")->fetchColumn();
        $accounts = (int) $this->db->query(
            "SELECT COUNT(*) FROM customer_payment_accounts WHERE account_type = 'qris' AND active = 1"
        )->fetchColumn();

        return [
            'pending' => $pending,
            'unmatched' => $unmatched,
            'qris_accounts' => $accounts,
            'preview' => $this->pending($days),
        ];
    }

    /**
     * Confirm a bank transfer against an active persistent QRIS account. The
     * amount is mandatory because the reusable QR carries no nominal.
     *
     * @return array{status:string,message:string}
     */
    public function confirmAccount(
        string $externalRef,
        int $amount,
        string $actor = 'admin',
        ?string $mutationRef = null,
    ): array {
        if ($amount < 1000) {
            throw new RuntimeException('Nominal pembayaran minimal Rp1.000.');
        }
        $account = $this->db->prepare(
            "SELECT * FROM customer_payment_accounts
             WHERE (external_ref = ? OR qris_unique_ref = ?) AND account_type = 'qris' AND active = 1
             LIMIT 1"
        );
        $account->execute([$externalRef, $externalRef]);
        if ($account->fetch() === false) {
            throw new RuntimeException('Akun QRIS unik tidak ditemukan atau sudah nonaktif.');
        }

        $result = $this->webhooks->confirmManual($externalRef, $actor, true, $amount, $mutationRef);
        $this->audit->record('admin', $actor, 'qris_unique.manual_confirm', 'payment_account', $externalRef, [
            'amount' => $amount,
            'mutation_ref' => $mutationRef,
        ]);

        return $result;
    }

    /**
     * @param list<string> $refs
     * @return list<array<string,mixed>>
     */
    public function preview(array $refs): array
    {
        $result = [];
        foreach (array_unique(array_filter(array_map('strval', $refs))) as $ref) {
            $row = $this->resolve($ref);
            $result[] = [
                'merchant_ref' => $ref,
                'found' => $row !== null,
                'kind' => $row['kind'] ?? null,
                'status' => $row['status'] ?? null,
                'amount' => isset($row['amount']) ? (int) $row['amount'] : null,
                'gateway' => $row['gateway'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * @param list<string> $refs
     * @return array{applied:int,failed:int,results:list<array<string,mixed>>}
     */
    public function applyBatch(array $refs, string $actor = 'admin', bool $force = false): array
    {
        $applied = 0;
        $failed = 0;
        $results = [];
        foreach (array_unique(array_filter(array_map('strval', $refs))) as $ref) {
            try {
                $result = $this->webhooks->confirmManual($ref, $actor, $force);
                if (($result['status'] ?? '') === 'processed' || ($result['status'] ?? '') === 'duplicate') {
                    $applied++;
                }
                $results[] = ['merchant_ref' => $ref] + $result;
            } catch (\Throwable $e) {
                $failed++;
                $results[] = [
                    'merchant_ref' => $ref,
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ];
            }
        }

        $this->audit->record('admin', $actor, 'reconciliation.batch', 'payment', null, [
            'applied' => $applied,
            'failed' => $failed,
            'refs' => array_values($refs),
        ]);

        return ['applied' => $applied, 'failed' => $failed, 'results' => $results];
    }

    /** @return array<string,mixed>|null */
    private function resolve(string $merchantRef): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT 'order' AS kind, * FROM orders WHERE merchant_ref = ? LIMIT 1"
        );
        $stmt->execute([$merchantRef]);
        $row = $stmt->fetch();
        if ($row) {
            return $row;
        }

        $stmt = $this->db->prepare(
            "SELECT 'transaction' AS kind, * FROM payment_transactions WHERE merchant_ref = ? LIMIT 1"
        );
        $stmt->execute([$merchantRef]);

        return $stmt->fetch() ?: null;
    }
}
