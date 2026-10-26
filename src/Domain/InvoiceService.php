<?php

declare(strict_types=1);

namespace Billas\Domain;

use Billas\Support\Settings;
use Billas\Support\Time;
use PDO;
use RuntimeException;
use Throwable;

final class InvoiceService
{
    public function __construct(
        private readonly PDO $db,
        private readonly Settings $settings,
        private readonly AuditService $audit,
        private readonly RetryService $retry,
    ) {
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT i.*, c.name AS customer_name, c.code AS customer_code, p.name AS package_name
             FROM invoices i
             JOIN customers c ON c.id = i.customer_id
             LEFT JOIN packages p ON p.id = c.package_id
             WHERE i.id = ?'
        );
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findRaw(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM invoices WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return list<array<string,mixed>> */
    public function forCustomer(int $customerId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM invoices WHERE customer_id = ? ORDER BY period DESC, id DESC');
        $stmt->execute([$customerId]);

        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function oldestOpen(int $customerId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM invoices WHERE customer_id = ? AND status = 'unpaid' ORDER BY due_date, id LIMIT 1"
        );
        $stmt->execute([$customerId]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Generate monthly invoices for active PPPoE customers, idempotently.
     *
     * @return array{period:string,created:int,skipped:int}
     */
    public function generateMonthly(?string $period = null): array
    {
        $period = $period ?: Time::currentPeriod();
        [$periodStart, $periodEnd] = Time::monthBounds($period);
        $dueDate = $period . '-10';

        $stmt = $this->db->query(
            "SELECT c.*, p.price AS package_price, p.name AS package_name
             FROM customers c
             JOIN packages p ON p.id = c.package_id
             WHERE c.status IN ('active','isolir') AND p.type = 'pppoe'"
        );
        $created = 0;
        $skipped = 0;
        foreach ($stmt->fetchAll() as $customer) {
            $exists = $this->db->prepare(
                "SELECT 1 FROM invoices WHERE customer_id = ? AND period = ? AND source = 'monthly'"
            );
            $exists->execute([$customer['id'], $period]);
            if ($exists->fetchColumn()) {
                $skipped++;
                continue;
            }

            $invoiceNo = $this->uniqueNumber($period);
            $this->db->prepare(
                'INSERT INTO invoices
                 (invoice_no, customer_id, period, period_start, period_end, amount, due_date, status, source, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, "unpaid", "monthly", ?)'
            )->execute([
                $invoiceNo,
                $customer['id'],
                $period,
                $periodStart,
                $periodEnd,
                (int) $customer['package_price'],
                $dueDate,
                Time::now(),
            ]);
            $created++;
            $this->audit->record('system', 'cron', 'invoice.generated', 'invoice', (string) $this->db->lastInsertId(), [
                'period' => $period,
                'customer_id' => $customer['id'],
            ]);
        }

        return ['period' => $period, 'created' => $created, 'skipped' => $skipped];
    }

    /**
     * Fitur C/D: mark an invoice paid and extend validity monotonically.
     *
     * @return array<string,mixed>|null
     */
    public function markPaid(int $invoiceId, ?int $paidViaAccountId = null): ?array
    {
        $invoice = $this->findRaw($invoiceId);
        if ($invoice === null || $invoice['status'] === 'paid') {
            return $this->find($invoiceId);
        }
        if ($invoice['status'] === 'void') {
            throw new RuntimeException('Invoice void tidak dapat dibayar.');
        }

        $now = Time::now();
        $this->db->beginTransaction();
        $reactivated = false;
        try {
            $this->db->prepare(
                'UPDATE invoices SET status = "paid", paid_at = ?, paid_via_account_id = ? WHERE id = ?'
            )->execute([$now, $paidViaAccountId, $invoiceId]);

            $customer = $this->customer((int) $invoice['customer_id']);
            $periodEnd = (string) ($invoice['period_end'] ?: $invoice['period'] . '-01');
            $guaranteeDays = max(0, $this->settings->int('pppoe_qris.guarantee_days', 0));
            $guaranteeDate = Time::addDays($periodEnd, $guaranteeDays);

            $currentPaidUntil = $customer['paid_until'] ?: '1970-01-01';
            $currentGuarantee = $customer['service_guaranteed_until'] ?: '1970-01-01';
            $newPaidUntil = max($currentPaidUntil, $periodEnd);
            $newGuarantee = max($currentGuarantee, $guaranteeDate);

            $this->db->prepare(
                'UPDATE customers
                 SET paid_until = ?, paid_until_updated_at = ?,
                     service_guaranteed_until = ?, service_guaranteed_updated_at = ?
                 WHERE id = ?'
            )->execute([$newPaidUntil, $now, $newGuarantee, $now, $customer['id']]);

            if ($customer['status'] === 'isolir' && $newGuarantee >= Time::today()) {
                $this->db->prepare("UPDATE customers SET status = 'active' WHERE id = ?")->execute([$customer['id']]);
                $reactivated = true;
            }

            $this->audit->record('system', 'webhook', 'invoice.paid', 'invoice', (string) $invoiceId, [
                'paid_until' => $newPaidUntil,
                'source' => $invoice['source'],
            ]);
            $this->audit->record('system', 'webhook', 'customer.guarantee_extended', 'customer', (string) $customer['id'], [
                'service_guaranteed_until' => $newGuarantee,
                'period_end' => $periodEnd,
            ]);
            if ($reactivated) {
                $this->audit->record('system', 'webhook', 'customer.reactivated', 'customer', (string) $customer['id'], null);
            }

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        if ($reactivated) {
            $customerId = (int) $invoice['customer_id'];
            $this->retry->enqueue('pppoe_enable', ['customer_id' => $customerId]);
            $this->retry->enqueue('pppoe_kick', ['customer_id' => $customerId]);
        }

        return $this->find($invoiceId);
    }

    /**
     * Apply a persistent-account / QRIS-unique payment. Pays the oldest invoice
     * or creates a prepaid invoice for the next period.
     *
     * @return array<string,mixed>
     */
    public function applyPersistentPayment(
        int $customerId,
        int $amount,
        ?int $accountId = null,
        ?string $externalRef = null,
    ): array {
        $customer = $this->customer($customerId);
        $open = $this->oldestOpen($customerId);
        if ($open !== null) {
            if ($amount < (int) $open['amount']) {
                throw new RuntimeException('Nominal pembayaran kurang dari tagihan.');
            }
            $paid = $this->markPaid((int) $open['id'], $accountId);
            $this->audit->record('system', 'webhook', 'payment.persistent_matched', 'invoice', (string) $open['id'], [
                'customer_id' => $customerId,
                'amount' => $amount,
                'external_ref' => $externalRef,
            ]);

            return ['invoice' => $paid, 'created_prepaid' => false];
        }

        $startBase = max(Time::today(), $customer['paid_until'] ?: Time::today());
        $periodStart = Time::addDays($startBase, 1);
        if ($periodStart < Time::today()) {
            $periodStart = Time::today();
        }
        $periodEnd = date('Y-m-d', strtotime($periodStart . ' +1 month -1 day'));
        $period = substr($periodStart, 0, 7);
        $invoiceNo = $this->uniqueNumber($period);
        $price = (int) ($this->packagePrice($customer) ?? $amount);
        $invoiceAmount = max($amount, $price);

        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                'INSERT INTO invoices
                 (invoice_no, customer_id, period, period_start, period_end, amount, due_date, status, source, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, "unpaid", "prepaid", ?)'
            )->execute([
                $invoiceNo, $customerId, $period, $periodStart, $periodEnd,
                $invoiceAmount, $periodStart, Time::now(),
            ]);
            $invoiceId = (int) $this->db->lastInsertId();
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        $paid = $this->markPaid($invoiceId, $accountId);
        $this->audit->record('system', 'webhook', 'payment.persistent_matched', 'invoice', (string) $invoiceId, [
            'customer_id' => $customerId,
            'amount' => $amount,
            'external_ref' => $externalRef,
            'prepaid' => true,
        ]);

        return ['invoice' => $paid, 'created_prepaid' => true];
    }

    /** @return array{expired:int} */
    public function expirePendingTransactions(): array
    {
        $stmt = $this->db->prepare(
            "UPDATE payment_transactions SET status = 'expired', updated_at = ?
             WHERE status = 'pending' AND expires_at <= ?"
        );
        $stmt->execute([Time::now(), Time::now()]);

        return ['expired' => $stmt->rowCount()];
    }

    /** @return array<string,mixed>|null */
    public function transaction(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT t.*, i.invoice_no, i.period_end, i.status AS invoice_status, c.name AS customer_name
             FROM payment_transactions t
             JOIN invoices i ON i.id = t.invoice_id
             JOIN customers c ON c.id = i.customer_id
             WHERE t.id = ?'
        );
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    /** @return array<string,mixed>|null */
    public function transactionByMerchantRef(string $merchantRef): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM payment_transactions WHERE merchant_ref = ?');
        $stmt->execute([$merchantRef]);

        return $stmt->fetch() ?: null;
    }

    public function markTransactionPaid(int $transactionId, ?int $paidAt = null): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE payment_transactions SET status = 'paid', paid_at = ?, updated_at = ?
             WHERE id = ? AND status = 'pending'"
        );
        $stmt->execute([$paidAt ?? Time::now(), Time::now(), $transactionId]);

        return $stmt->rowCount() > 0;
    }

    /** @return list<array<string,mixed>> */
    public function listAdmin(string $status = '', string $period = ''): array
    {
        $sql = 'SELECT i.*, c.name AS customer_name, c.code AS customer_code
                FROM invoices i JOIN customers c ON c.id = i.customer_id WHERE 1 = 1';
        $params = [];
        if (in_array($status, ['unpaid', 'paid', 'void', 'expired'], true)) {
            $sql .= ' AND i.status = ?';
            $params[] = $status;
        }
        if ($period !== '') {
            $sql .= ' AND i.period = ?';
            $params[] = $period;
        }
        $sql .= ' ORDER BY i.period DESC, i.id DESC LIMIT 300';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @return array<string,mixed> */
    private function customer(int $customerId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM customers WHERE id = ?');
        $stmt->execute([$customerId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException('Customer tidak ditemukan.');
        }

        return $row;
    }

    private function packagePrice(array $customer): ?int
    {
        if (empty($customer['package_id'])) {
            return null;
        }
        $stmt = $this->db->prepare('SELECT price FROM packages WHERE id = ?');
        $stmt->execute([$customer['package_id']]);
        $price = $stmt->fetchColumn();

        return $price === false ? null : (int) $price;
    }

    private function uniqueNumber(string $period): string
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $no = 'INV-' . str_replace('-', '', $period) . '-' . strtoupper(bin2hex(random_bytes(3)));
            $stmt = $this->db->prepare('SELECT 1 FROM invoices WHERE invoice_no = ?');
            $stmt->execute([$no]);
            if (!$stmt->fetchColumn()) {
                return $no;
            }
        }

        throw new RuntimeException('Tidak dapat membuat nomor invoice.');
    }
}
