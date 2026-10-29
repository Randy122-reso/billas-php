<?php

declare(strict_types=1);

namespace Billas\Domain;

use Billas\Domain\PaymentGateway\GatewayFactory;
use Billas\Support\Settings;
use Billas\Support\Time;
use PDO;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Unified webhook pipeline:
 * verify -> idempotency -> normalize -> dispatch order/invoice/persistent.
 */
final class WebhookService
{
    public function __construct(
        private readonly PDO $db,
        private readonly GatewayFactory $gateways,
        private readonly OrderService $orders,
        private readonly InvoiceService $invoices,
        private readonly VoucherService $vouchers,
        private readonly CaptivePortalService $captive,
        private readonly CustomerPaymentAccountService $accounts,
        private readonly PackageService $packages,
        private readonly AuditService $audit,
        private readonly LoggerInterface $logger,
        private readonly Settings $settings,
    ) {
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:string,message:string,event_id?:int,voucher_id?:int}
     */
    public function handle(
        string $providerName,
        array $headers,
        string $rawBody,
        bool $simulated = false,
        bool $bypassSignature = false,
    ): array {
        $headers = $this->normalizeHeaders($headers);
        $provider = $this->gateways->make($providerName);

        if ($bypassSignature && !$simulated) {
            throw new RuntimeException('Bypass signature hanya diizinkan untuk webhook simulasi.');
        }
        if ($simulated) {
            $this->assertSimulatedAllowed($providerName, $headers);
        }
        if (!$bypassSignature) {
            if (!$provider->isConfigured()) {
                throw new RuntimeException('Gateway belum dikonfigurasi: ' . $providerName);
            }
            if (!$provider->verifyWebhook($headers, $rawBody)) {
                $this->audit->record('system', 'webhook', 'webhook.invalid_signature', 'gateway', $providerName, null);
                throw new RuntimeException('Signature webhook tidak valid.');
            }
        }

        $normalized = $simulated
            ? $provider->normalizeSimulatedWebhook($headers, $rawBody)
            : $provider->normalizeWebhook($headers, $rawBody);
        $eventId = $this->beginEvent($providerName, $normalized['event_key'], $normalized['merchant_ref'], $rawBody);
        if ($eventId === null) {
            return ['status' => 'duplicate', 'message' => 'Event sudah pernah diproses.'];
        }

        try {
            if ($normalized['status'] !== 'paid') {
                if ($simulated) {
                    $result = $this->processSimulatedNonPaid($normalized);
                    $this->finishEvent($eventId, (string) $result['status'], (string) $result['message']);

                    return ['event_id' => $eventId] + $result;
                }
                $this->finishEvent($eventId, 'ignored', 'Status: ' . $normalized['status']);

                return ['status' => 'ignored', 'message' => 'Event bukan pembayaran sukses.', 'event_id' => $eventId];
            }

            $result = $this->processPaid(
                (string) $normalized['merchant_ref'],
                (int) $normalized['amount'],
                $normalized['external_ref'] ?? null,
                $normalized['gateway_reference'] ?? null,
                $normalized['paid_at'] ?? null,
                $normalized['kind'] ?? 'auto'
            );
            $this->finishEvent($eventId, (string) $result['status'], (string) $result['message']);

            return ['event_id' => $eventId] + $result;
        } catch (Throwable $e) {
            $this->failEvent($eventId, $e->getMessage());
            throw $e;
        }
    }

    /**
     * Manual reconciliation (or local simulation). Never trust this method from
     * a public route.
     *
     * For a persistent QRIS account there is no order/transaction row, so the
     * operator must supply the amount seen in the bank statement. The optional
     * mutation reference makes each monthly payment idempotent without blocking
     * a later payment to the same reusable QRIS.
     *
     * @return array{status:string,message:string,voucher_id?:int}
     */
    public function confirmManual(
        string $merchantRef,
        string $actor = 'admin',
        bool $force = false,
        ?int $amount = null,
        ?string $mutationRef = null,
    ): array {
        $order = $this->orders->findByMerchantRef($merchantRef);
        $transaction = $order === null ? $this->invoices->transactionByMerchantRef($merchantRef) : null;
        $account = ($order === null && $transaction === null)
            ? $this->accounts->resolveByExternalRef($merchantRef)
            : null;
        if ($order === null && $transaction === null && $account === null) {
            throw new RuntimeException('Referensi pembayaran tidak ditemukan.');
        }
        $gateway = (string) ($order['gateway'] ?? $transaction['gateway'] ?? $account['gateway'] ?? '');
        if (!$force && $gateway !== 'qris_static') {
            throw new RuntimeException('Konfirmasi manual hanya untuk QRIS statis.');
        }
        $amount = (int) ($order['amount'] ?? $transaction['amount'] ?? $amount ?? 0);
        if ($amount <= 0) {
            throw new RuntimeException('Nominal pembayaran wajib diisi untuk akun QRIS persisten.');
        }
        $eventKey = 'manual:' . $merchantRef . ':paid';
        if ($account !== null) {
            $eventKey .= ':' . $amount . ':' . ($mutationRef !== null && $mutationRef !== '' ? $mutationRef : Time::now());
        }
        $existing = $this->eventByKey($eventKey);
        if ($existing !== null && $existing['status'] === 'processed') {
            return ['status' => 'duplicate', 'message' => 'Pembayaran sudah dikonfirmasi.'];
        }
        if ($existing !== null) {
            $this->db->prepare('DELETE FROM webhook_events WHERE id = ?')->execute([$existing['id']]);
        }
        $eventId = $this->beginEvent('manual', $eventKey, $merchantRef, json_encode([
            'merchant_ref' => $merchantRef,
            'actor' => $actor,
            'force' => $force,
            'amount' => $amount,
            'mutation_ref' => $mutationRef,
        ]) ?: '{}');
        if ($eventId === null) {
            return ['status' => 'duplicate', 'message' => 'Event sudah diproses.'];
        }

        try {
            $result = $this->processPaid($merchantRef, $amount, null, 'manual-' . $merchantRef, Time::now(), 'auto');
            $this->finishEvent($eventId, (string) $result['status'], (string) $result['message']);
            $this->audit->record('admin', $actor, 'payment.manual_confirm', 'payment', $merchantRef, [
                'amount' => $amount,
                'force' => $force,
                'persistent_account' => $account !== null,
                'mutation_ref' => $mutationRef,
            ]);

            return ['event_id' => $eventId] + $result;
        } catch (Throwable $e) {
            $this->failEvent($eventId, $e->getMessage());
            throw $e;
        }
    }

    /**
     * @return array{status:string,message:string,voucher_id?:int}
     */
    private function processPaid(
        string $merchantRef,
        int $amount,
        ?string $externalRef,
        ?string $gatewayReference,
        ?int $paidAt,
        string $kind,
    ): array {
        $order = $this->orders->findByMerchantRef($merchantRef);
        if ($order !== null) {
            return $this->processOrder($order, $amount, $gatewayReference, $paidAt);
        }

        $transaction = $this->invoices->transactionByMerchantRef($merchantRef);
        if ($transaction !== null) {
            return $this->processInvoice($transaction, $amount, $paidAt);
        }

        $reference = $externalRef ?: $merchantRef;
        $account = $this->accounts->resolveByExternalRef($reference);
        if ($account !== null) {
            return $this->processPersistent($account, $amount, $reference);
        }

        $this->audit->record('system', 'webhook', 'webhook.unmatched', 'merchant_ref', $merchantRef, ['amount' => $amount]);

        return ['status' => 'unmatched', 'message' => 'Pembayaran tidak cocok dengan order/invoice/akun.'];
    }

    /** @return array{status:string,message:string,voucher_id?:int} */
    private function processOrder(array $order, int $amount, ?string $gatewayReference, ?int $paidAt): array
    {
        if ((int) $order['amount'] !== $amount) {
            $this->audit->record('system', 'webhook', 'webhook.amount_mismatch', 'order', (string) $order['id'], [
                'expected' => (int) $order['amount'],
                'received' => $amount,
            ]);

            return ['status' => 'unmatched', 'message' => 'Nominal pembayaran tidak cocok.'];
        }

        $existingVoucher = $this->vouchers->findByOrderId((string) $order['id']);
        if ($existingVoucher !== null && $order['status'] === 'paid') {
            return ['status' => 'processed', 'message' => 'Order sudah dibayar.', 'voucher_id' => (int) $existingVoucher['id']];
        }

        $package = $this->packages->find((int) $order['package_id']);
        if ($package === null) {
            throw new RuntimeException('Paket order tidak ditemukan.');
        }
        $session = $order['source'] === 'captive_portal' ? $this->captive->findByOrderId((string) $order['id']) : null;

        if ($order['source'] === 'captive_portal') {
            $voucher = $this->vouchers->persistCaptive($order, $package, [
                'router_id' => $session['router_id'] ?? null,
            ]);
            $this->captive->markPaidByOrder((string) $order['id'], (int) $voucher['id']);
        } else {
            $voucher = $this->vouchers->persist($order, $package, [
                'client_ip' => $order['client_ip'] ?? null,
                'client_mac' => $order['client_mac'] ?? null,
            ]);
        }

        $this->orders->markPaid((string) $order['id'], $gatewayReference, $paidAt);
        if ($session !== null && ($session['status'] ?? '') === 'pending') {
            $this->captive->markPaid((string) $session['session_token'], (string) $order['id'], (int) $voucher['id']);
        }
        $this->audit->record('system', 'webhook', 'order.paid', 'order', (string) $order['id'], [
            'gateway_reference' => $gatewayReference,
            'amount' => $amount,
            'source' => $order['source'],
        ]);

        return ['status' => 'processed', 'message' => 'Pembayaran diproses.', 'voucher_id' => (int) $voucher['id']];
    }

    /** @return array{status:string,message:string} */
    private function processInvoice(array $transaction, int $amount, ?int $paidAt): array
    {
        if ((int) $transaction['amount'] !== $amount) {
            $this->audit->record('system', 'webhook', 'webhook.amount_mismatch', 'payment', (string) $transaction['id'], [
                'expected' => (int) $transaction['amount'],
                'received' => $amount,
            ]);

            return ['status' => 'unmatched', 'message' => 'Nominal pembayaran tidak cocok.'];
        }
        if ($transaction['status'] === 'paid') {
            return ['status' => 'processed', 'message' => 'Transaksi sudah dibayar.'];
        }

        $this->invoices->markTransactionPaid((int) $transaction['id'], $paidAt);
        $this->invoices->markPaid((int) $transaction['invoice_id']);
        $this->audit->record('system', 'webhook', 'payment.invoice_paid', 'payment', (string) $transaction['id'], [
            'invoice_id' => $transaction['invoice_id'],
            'amount' => $amount,
        ]);

        return ['status' => 'processed', 'message' => 'Invoice dilunasi.'];
    }

    /** @return array{status:string,message:string} */
    private function processPersistent(array $account, int $amount, string $reference): array
    {
        if ((int) $account['active'] !== 1) {
            $this->audit->record('system', 'webhook', 'qris_unique.unmatched_payment', 'payment_account', (string) $account['id'], [
                'amount' => $amount,
                'external_ref' => $reference,
            ]);

            return ['status' => 'unmatched', 'message' => 'Akun pembayaran nonaktif.'];
        }

        $this->invoices->applyPersistentPayment(
            (int) $account['customer_id'],
            $amount,
            (int) $account['id'],
            $reference
        );

        return ['status' => 'processed', 'message' => 'Pembayaran akun persisten diterapkan.'];
    }

    /**
     * Simulated EXPIRED/FAILED events update pending state so operators can
     * exercise the full lifecycle. Real webhooks keep the v4 behaviour (ignored).
     *
     * @param array<string,mixed> $normalized
     * @return array{status:string,message:string}
     */
    private function processSimulatedNonPaid(array $normalized): array
    {
        $status = (string) ($normalized['status'] ?? '');
        if (!in_array($status, ['expired', 'failed'], true)) {
            return ['status' => 'ignored', 'message' => 'Status simulasi tidak mengubah data.'];
        }

        $merchantRef = (string) ($normalized['merchant_ref'] ?? '');
        if ($merchantRef === '') {
            return ['status' => 'unmatched', 'message' => 'Referensi pembayaran tidak ditemukan.'];
        }

        $order = $this->orders->findByMerchantRef($merchantRef);
        if ($order !== null) {
            if ($order['status'] === 'paid') {
                return ['status' => 'ignored', 'message' => 'Order sudah dibayar.'];
            }
            $stmt = $this->db->prepare(
                "UPDATE orders SET status = ? WHERE id = ? AND status IN ('pending','processing')"
            );
            $stmt->execute([$status, $order['id']]);
            if ($stmt->rowCount() > 0) {
                $this->audit->record('system', 'webhook', 'order.' . $status, 'order', (string) $order['id'], [
                    'amount' => (int) ($normalized['amount'] ?? 0),
                    'simulated' => true,
                ]);

                return ['status' => 'processed', 'message' => 'Order ditandai ' . $status . '.'];
            }

            return ['status' => 'ignored', 'message' => 'Order tidak berstatus pending.'];
        }

        $transaction = $this->invoices->transactionByMerchantRef($merchantRef);
        if ($transaction !== null) {
            if ($transaction['status'] === 'paid') {
                return ['status' => 'ignored', 'message' => 'Transaksi sudah dibayar.'];
            }
            $stmt = $this->db->prepare(
                "UPDATE payment_transactions SET status = ?, updated_at = ? WHERE id = ? AND status = 'pending'"
            );
            $stmt->execute([$status, Time::now(), $transaction['id']]);
            if ($stmt->rowCount() > 0) {
                $this->audit->record('system', 'webhook', 'payment.' . $status, 'payment', (string) $transaction['id'], [
                    'invoice_id' => $transaction['invoice_id'],
                    'simulated' => true,
                ]);

                return ['status' => 'processed', 'message' => 'Transaksi ditandai ' . $status . '.'];
            }

            return ['status' => 'ignored', 'message' => 'Transaksi tidak berstatus pending.'];
        }

        $reference = (string) ($normalized['external_ref'] ?? '') ?: $merchantRef;
        if ($this->accounts->resolveByExternalRef($reference) !== null) {
            return ['status' => 'ignored', 'message' => 'Akun persisten tidak memiliki status expired/failed.'];
        }

        $this->audit->record('system', 'webhook', 'webhook.unmatched', 'merchant_ref', $merchantRef, [
            'amount' => (int) ($normalized['amount'] ?? 0),
            'simulated' => true,
        ]);

        return ['status' => 'unmatched', 'message' => 'Pembayaran tidak cocok dengan order/invoice/akun.'];
    }

    /**
     * @param array<string,string> $headers
     */
    private function assertSimulatedAllowed(string $providerName, array $headers): void
    {
        if (($headers['x-billas-simulated'] ?? '') !== '1') {
            throw new RuntimeException('Simulated webhook tanpa header X-Billas-Simulated.');
        }
        if (!$this->settings->feature('webhook_simulator')) {
            throw new RuntimeException('Simulator dimatikan.');
        }
        $this->logger->warning('Webhook simulated', ['provider' => $providerName]);
    }

    /**
     * HTTP header names are case-insensitive. Normalize once so providers can
     * rely on lowercase keys regardless of whether the caller came from Slim,
     * PHP's $_SERVER superglobal, or the admin simulator.
     *
     * @param array<string,mixed> $headers
     * @return array<string,string>
     */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            if (is_array($value)) {
                $value = implode(',', array_map('strval', $value));
            }
            $normalized[strtolower((string) $name)] = (string) $value;
        }

        return $normalized;
    }

    private function beginEvent(string $provider, string $eventKey, ?string $merchantRef, string $payload): ?int
    {
        $existing = $this->eventByKey($eventKey);
        if ($existing !== null) {
            if ($existing['status'] !== 'failed') {
                return null;
            }
            $this->db->prepare('DELETE FROM webhook_events WHERE id = ?')->execute([$existing['id']]);
        }

        $stmt = $this->db->prepare(
            'INSERT INTO webhook_events (provider, event_key, merchant_ref, status, payload, received_at)
             VALUES (?, ?, ?, "received", ?, ?)'
        );
        $stmt->execute([$provider, $eventKey, $merchantRef, mb_substr($payload, 0, 100_000), Time::now()]);

        return (int) $this->db->lastInsertId();
    }

    private function finishEvent(int $eventId, string $status, string $note): void
    {
        $eventStatus = match ($status) {
            'processed', 'duplicate' => 'processed',
            'unmatched' => 'unmatched',
            'ignored' => 'ignored',
            default => 'processed',
        };
        $this->db->prepare('UPDATE webhook_events SET status = ?, note = ?, processed_at = ? WHERE id = ?')
            ->execute([$eventStatus, mb_substr($note, 0, 1000), Time::now(), $eventId]);
    }

    private function failEvent(int $eventId, string $message): void
    {
        $this->db->prepare('UPDATE webhook_events SET status = "failed", note = ?, processed_at = ? WHERE id = ?')
            ->execute([mb_substr($message, 0, 1000), Time::now(), $eventId]);
        $this->logger->error('Webhook gagal diproses', ['event_id' => $eventId, 'error' => $message]);
    }

    /** @return array<string,mixed>|null */
    private function eventByKey(string $eventKey): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM webhook_events WHERE event_key = ?');
        $stmt->execute([$eventKey]);

        return $stmt->fetch() ?: null;
    }
}
