<?php

declare(strict_types=1);

namespace Billas\Domain\PaymentGateway;

use RuntimeException;

final class DuitkuService extends BaseProvider
{
    public function name(): string
    {
        return 'duitku';
    }

    public function isConfigured(): bool
    {
        return $this->env->string('DUITKU_MERCHANT_CODE') !== '' && $this->env->string('DUITKU_API_KEY') !== '';
    }

    public function createTransaction(array $context): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Duitku belum dikonfigurasi.');
        }
        $base = $this->env->string('DUITKU_MODE', 'sandbox') === 'production'
            ? 'https://api-prod.duitku.com'
            : 'https://api-sandbox.duitku.com';
        $merchantCode = $this->env->string('DUITKU_MERCHANT_CODE');
        $apiKey = $this->env->string('DUITKU_API_KEY');
        $merchantRef = (string) $context['merchant_ref'];
        $amount = (int) $context['amount'];
        $payload = [
            'merchantCode' => $merchantCode,
            'paymentAmount' => $amount,
            'paymentMethod' => 'QRIS',
            'merchantOrderId' => $merchantRef,
            'productDetails' => mb_substr((string) ($context['description'] ?? 'Paket Billas'), 0, 50),
            'email' => (string) ($context['customer_email'] ?? 'noreply@example.test'),
            'phoneNumber' => (string) ($context['customer_phone'] ?? '0800000000'),
            'customerVaName' => mb_substr((string) ($context['customer_name'] ?? 'Pelanggan'), 0, 20),
            'callbackUrl' => $context['callback_url'] ?? $this->callbackUrl(),
            'returnUrl' => $context['return_url'] ?? rtrim($this->env->string('APP_URL'), '/'),
            'expiryPeriod' => 15,
            'signature' => md5($merchantCode . $merchantRef . $amount . $apiKey),
        ];
        $response = $this->postJson($base . '/api/merchant/v2/inquiry', $payload);
        if (!isset($response['reference'])) {
            throw new RuntimeException('Duitku: ' . (string) ($response['statusMessage'] ?? 'respons tidak valid'));
        }

        return [
            'reference' => (string) $response['reference'],
            'qr_string' => $response['qrString'] ?? null,
            'checkout_url' => $response['paymentUrl'] ?? null,
            'expires_at' => (int) ($context['expires_at'] ?? time() + 900),
            'payload' => $response,
        ];
    }

    public function verifyWebhook(array $headers, string $rawBody): bool
    {
        $data = [];
        parse_str($rawBody, $data);
        $merchantCode = (string) ($data['merchantCode'] ?? '');
        $amount = (string) ($data['amount'] ?? '');
        $merchantOrderId = (string) ($data['merchantOrderId'] ?? '');
        $signature = (string) ($data['signature'] ?? '');
        $apiKey = $this->env->string('DUITKU_API_KEY');
        if ($apiKey === '' || $signature === '') {
            return false;
        }
        $expected = md5($merchantCode . $amount . $merchantOrderId . $apiKey);

        return hash_equals($expected, $signature);
    }

    public function normalizeWebhook(array $headers, string $rawBody): array
    {
        $data = [];
        parse_str($rawBody, $data);
        $resultCode = (string) ($data['resultCode'] ?? '');
        $status = $resultCode === '00' ? 'paid' : ($resultCode === '01' ? 'pending' : 'failed');
        $merchantRef = (string) ($data['merchantOrderId'] ?? '');
        $reference = (string) ($data['reference'] ?? '');

        return [
            'merchant_ref' => $merchantRef !== '' ? $merchantRef : null,
            'external_ref' => null,
            'amount' => $this->amountInt($data['amount'] ?? 0),
            'status' => $status,
            'gateway_reference' => $reference !== '' ? $reference : null,
            'paid_at' => null,
            'event_key' => 'duitku:' . ($reference !== '' ? $reference : $merchantRef) . ':' . $resultCode,
            'kind' => 'auto',
        ];
    }

    public function supportsPersistent(string $accountType = 'va'): bool
    {
        return $accountType === 'qris' && $this->env->bool('QRIS_STATIC_ENABLED', true);
    }

    public function buildSimulatedWebhook(array $target, string $event, ?int $amountOverride = null): array
    {
        $merchantCode = $this->env->string('DUITKU_MERCHANT_CODE');
        $apiKey = $this->env->string('DUITKU_API_KEY');
        $merchantOrderId = $this->simulatedRef($target);
        $amount = $this->simulatedAmount($target, $amountOverride);
        $resultCode = match ($event) {
            'PAID' => '00',
            'EXPIRED' => '02',
            default => '03',
        };
        $reference = 'SIM-' . strtoupper(bin2hex(random_bytes(4)));
        $body = [
            'merchantCode' => $merchantCode,
            'amount' => (string) $amount,
            'merchantOrderId' => $merchantOrderId,
            'reference' => $reference,
            'resultCode' => $resultCode,
            'statusMessage' => match ($event) {
                'PAID' => 'SUCCESS',
                'EXPIRED' => 'EXPIRED',
                default => 'FAILED',
            },
            // Duitku signs the callback body itself (form-urlencoded).
            'signature' => md5($merchantCode . $amount . $merchantOrderId . $apiKey),
        ];

        return [
            'body' => $body,
            'headers' => [],
            'body_format' => 'form',
        ];
    }

    public function normalizeSimulatedWebhook(array $headers, string $rawBody): array
    {
        $data = [];
        parse_str($rawBody, $data);
        $resultCode = (string) ($data['resultCode'] ?? '');
        $status = match ($resultCode) {
            '00' => 'paid',
            '02' => 'expired',
            '03' => 'failed',
            default => 'pending',
        };
        $merchantRef = (string) ($data['merchantOrderId'] ?? '');
        $reference = (string) ($data['reference'] ?? '');

        return [
            'merchant_ref' => $merchantRef !== '' ? $merchantRef : null,
            'external_ref' => null,
            'amount' => $this->amountInt($data['amount'] ?? 0),
            'status' => $status,
            'gateway_reference' => $reference !== '' ? $reference : null,
            'paid_at' => $status === 'paid' ? time() : null,
            'event_key' => 'duitku:' . ($reference !== '' ? $reference : $merchantRef) . ':' . $resultCode,
            'kind' => 'auto',
        ];
    }
}
