<?php

declare(strict_types=1);

namespace Billas\Domain\PaymentGateway;

use RuntimeException;

final class MidtransService extends BaseProvider
{
    public function name(): string
    {
        return 'midtrans';
    }

    public function isConfigured(): bool
    {
        return $this->env->string('MIDTRANS_SERVER_KEY') !== '';
    }

    public function createTransaction(array $context): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Midtrans belum dikonfigurasi.');
        }
        $base = $this->env->string('MIDTRANS_MODE', 'sandbox') === 'production'
            ? 'https://api.midtrans.com/v2'
            : 'https://api.sandbox.midtrans.com/v2';
        $payload = [
            'payment_type' => 'qris',
            'transaction_details' => [
                'order_id' => (string) $context['merchant_ref'],
                'gross_amount' => (int) $context['amount'],
            ],
            'customer_details' => [
                'first_name' => mb_substr((string) ($context['customer_name'] ?? 'Pelanggan'), 0, 50),
                'email' => (string) ($context['customer_email'] ?? 'noreply@example.test'),
                'phone' => (string) ($context['customer_phone'] ?? '0800000000'),
            ],
            'custom_expiry' => [
                'expiry_duration' => max(1, (int) ceil(((int) ($context['expires_at'] ?? time() + 900) - time()) / 60)),
                'unit' => 'minute',
            ],
        ];
        $auth = base64_encode($this->env->string('MIDTRANS_SERVER_KEY') . ':');
        $response = $this->postJson($base . '/charge', $payload, ['Authorization' => 'Basic ' . $auth]);
        if (isset($response['status_code']) && (string) $response['status_code'] !== '201' && (string) $response['status_code'] !== '200') {
            throw new RuntimeException('Midtrans: ' . (string) ($response['status_message'] ?? 'charge gagal'));
        }
        $actions = is_array($response['actions'] ?? null) ? $response['actions'] : [];
        $qrUrl = null;
        foreach ($actions as $action) {
            if (($action['name'] ?? '') === 'generate_qr_code') {
                $qrUrl = $action['url'] ?? null;
                break;
            }
        }

        return [
            'reference' => (string) ($response['transaction_id'] ?? $context['merchant_ref']),
            'qr_string' => $response['qr_string'] ?? null,
            'checkout_url' => $qrUrl,
            'expires_at' => (int) ($context['expires_at'] ?? time() + 900),
            'payload' => $response + ['qr_url' => $qrUrl],
        ];
    }

    public function verifyWebhook(array $headers, string $rawBody): bool
    {
        $data = json_decode($rawBody, true);
        if (!is_array($data)) {
            return false;
        }
        $serverKey = $this->env->string('MIDTRANS_SERVER_KEY');
        if ($serverKey === '') {
            return false;
        }
        $orderId = (string) ($data['order_id'] ?? '');
        $statusCode = (string) ($data['status_code'] ?? '');
        $gross = (string) ($data['gross_amount'] ?? '');
        $signature = (string) ($data['signature_key'] ?? '');
        if ($signature === '') {
            return false;
        }

        return hash_equals(hash('sha512', $orderId . $statusCode . $gross . $serverKey), $signature);
    }

    public function normalizeWebhook(array $headers, string $rawBody): array
    {
        $data = json_decode($rawBody, true);
        if (!is_array($data)) {
            throw new RuntimeException('Payload Midtrans tidak valid.');
        }
        $status = strtolower((string) ($data['transaction_status'] ?? ''));
        $fraud = strtolower((string) ($data['fraud_status'] ?? ''));
        $mapped = match ($status) {
            'capture', 'settlement' => $fraud === 'challenge' ? 'pending' : 'paid',
            'deny', 'cancel', 'failure' => 'failed',
            'expire' => 'expired',
            default => 'pending',
        };
        $transactionId = (string) ($data['transaction_id'] ?? '');
        $orderId = (string) ($data['order_id'] ?? '');

        return [
            'merchant_ref' => $orderId !== '' ? $orderId : null,
            'external_ref' => null,
            'amount' => $this->amountInt($data['gross_amount'] ?? 0),
            'status' => $mapped,
            'gateway_reference' => $transactionId !== '' ? $transactionId : null,
            'paid_at' => isset($data['settlement_time']) ? (int) strtotime((string) $data['settlement_time']) : null,
            'event_key' => 'midtrans:' . ($transactionId !== '' ? $transactionId : $orderId) . ':' . $status,
            'kind' => 'auto',
        ];
    }

    public function supportsPersistent(string $accountType = 'va'): bool
    {
        return $accountType === 'qris' && $this->env->bool('QRIS_STATIC_ENABLED', true);
    }

    public function buildSimulatedWebhook(array $target, string $event, ?int $amountOverride = null): array
    {
        $orderId = $this->simulatedRef($target);
        $amount = $this->simulatedAmount($target, $amountOverride);
        $statusCode = $event === 'PAID' ? '200' : '201';
        $transactionStatus = match ($event) {
            'PAID' => 'settlement',
            'EXPIRED' => 'expire',
            default => 'failure',
        };
        $body = [
            'order_id' => $orderId,
            'status_code' => $statusCode,
            'gross_amount' => (string) $amount,
            'transaction_status' => $transactionStatus,
            'fraud_status' => 'accept',
            'payment_type' => 'qris',
            'transaction_id' => 'SIM-' . strtoupper(bin2hex(random_bytes(4))),
            'transaction_time' => date('Y-m-d H:i:s'),
        ];
        if ($event === 'PAID') {
            $body['settlement_time'] = date('Y-m-d H:i:s');
        }
        $body['signature_key'] = hash(
            'sha512',
            $body['order_id'] . $body['status_code'] . $body['gross_amount'] . $this->env->string('MIDTRANS_SERVER_KEY')
        );

        return [
            'body' => $body,
            'headers' => ['X-Signature-Key' => $body['signature_key']],
            'body_format' => 'json',
        ];
    }
}
