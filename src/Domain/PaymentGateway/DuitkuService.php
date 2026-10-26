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
}
