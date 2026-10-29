<?php

declare(strict_types=1);

namespace Billas\Domain\PaymentGateway;

use RuntimeException;

final class TripayService extends BaseProvider
{
    public function name(): string
    {
        return 'tripay';
    }

    public function isConfigured(): bool
    {
        return $this->env->string('TRIPAY_API_KEY') !== ''
            && $this->env->string('TRIPAY_PRIVATE_KEY') !== ''
            && $this->env->string('TRIPAY_MERCHANT_CODE') !== '';
    }

    public function createTransaction(array $context): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Tripay belum dikonfigurasi.');
        }
        $merchantRef = (string) $context['merchant_ref'];
        $amount = (int) $context['amount'];
        $merchantCode = $this->env->string('TRIPAY_MERCHANT_CODE');
        $privateKey = $this->env->string('TRIPAY_PRIVATE_KEY');
        $base = $this->env->string('TRIPAY_MODE', 'sandbox') === 'production'
            ? 'https://tripay.co.id/api'
            : 'https://tripay.co.id/api-sandbox';

        $signature = hash_hmac('sha256', $merchantCode . $merchantRef . $amount, $privateKey);
        $payload = [
            'method' => $this->env->string('TRIPAY_METHOD', 'QRIS'),
            'merchant_ref' => $merchantRef,
            'amount' => $amount,
            'customer_name' => (string) ($context['customer_name'] ?? 'Pelanggan Billas'),
            'customer_email' => (string) ($context['customer_email'] ?? 'noreply@example.test'),
            'customer_phone' => (string) ($context['customer_phone'] ?? '0800000000'),
            'order_items' => [[
                'sku' => 'PKG-' . ($context['package_id'] ?? '0'),
                'name' => mb_substr((string) ($context['description'] ?? 'Paket Billas'), 0, 50),
                'price' => $amount,
                'quantity' => 1,
            ]],
            'callback_url' => $context['callback_url'] ?? $this->callbackUrl(),
            'return_url' => $context['return_url'] ?? rtrim($this->env->string('APP_URL'), '/'),
            'expired_time' => (int) ($context['expires_at'] ?? (time() + 900)),
            'signature' => $signature,
        ];

        $response = $this->postJson($base . '/transaction/create', $payload, [
            'Authorization' => 'Bearer ' . $this->env->string('TRIPAY_API_KEY'),
        ]);
        if (empty($response['success'])) {
            throw new RuntimeException('Tripay: ' . (string) ($response['message'] ?? 'respons tidak valid'));
        }
        $data = $response['data'] ?? [];
        if (!isset($data['reference'])) {
            throw new RuntimeException('Tripay tidak mengembalikan reference.');
        }

        return [
            'reference' => (string) $data['reference'],
            'qr_string' => $data['qr_string'] ?? null,
            'checkout_url' => $data['checkout_url'] ?? $data['pay_url'] ?? null,
            'expires_at' => isset($data['expired_time']) ? (int) $data['expired_time'] : (int) ($context['expires_at'] ?? time() + 900),
            'payload' => $data,
        ];
    }

    public function verifyWebhook(array $headers, string $rawBody): bool
    {
        $signature = $headers['x-callback-signature'] ?? '';
        if ($signature === '') {
            return false;
        }
        $privateKey = $this->env->string('TRIPAY_PRIVATE_KEY');

        return $privateKey !== '' && hash_equals(hash_hmac('sha256', $rawBody, $privateKey), $signature);
    }

    public function normalizeWebhook(array $headers, string $rawBody): array
    {
        $data = json_decode($rawBody, true);
        if (!is_array($data)) {
            throw new RuntimeException('Payload Tripay tidak valid.');
        }
        $status = strtoupper((string) ($data['status'] ?? ''));
        $mapped = match ($status) {
            'PAID' => 'paid',
            'EXPIRED' => 'expired',
            'FAILED' => 'failed',
            'REFUND' => 'failed',
            default => 'pending',
        };
        $reference = (string) ($data['reference'] ?? '');
        $merchantRef = (string) ($data['merchant_ref'] ?? '');

        return [
            'merchant_ref' => $merchantRef !== '' ? $merchantRef : null,
            'external_ref' => null,
            'amount' => $this->amountInt($data['amount'] ?? $data['total_amount'] ?? 0),
            'status' => $mapped,
            'gateway_reference' => $reference !== '' ? $reference : null,
            'paid_at' => isset($data['paid_at']) ? (int) $data['paid_at'] : (isset($data['paid_at_unix']) ? (int) $data['paid_at_unix'] : null),
            'event_key' => 'tripay:' . $reference . ':' . $status,
            'kind' => 'auto',
        ];
    }

    public function supportsPersistent(string $accountType = 'va'): bool
    {
        return false;
    }

    public function buildSimulatedWebhook(array $target, string $event, ?int $amountOverride = null): array
    {
        $merchantRef = $this->simulatedRef($target);
        $amount = $this->simulatedAmount($target, $amountOverride);
        $body = [
            'reference' => 'SIM-' . strtoupper(bin2hex(random_bytes(4))),
            'merchant_ref' => $merchantRef,
            'status' => $event === 'PAID' ? 'PAID' : strtoupper($event),
            'total_amount' => $amount,
            'amount' => $amount,
            'payment_method' => 'QRIS',
        ];
        if ($event === 'PAID') {
            $body['paid_at'] = time();
        }

        return [
            'body' => $body,
            'headers' => [
                'X-Callback-Signature' => hash_hmac(
                    'sha256',
                    $this->simulatedJson($body),
                    $this->env->string('TRIPAY_PRIVATE_KEY')
                ),
            ],
            'body_format' => 'json',
        ];
    }

    public function createPersistentAccount(array $context): array
    {
        throw new RuntimeException('Tripay tidak mendukung akun pembayaran persisten.');
    }
}
