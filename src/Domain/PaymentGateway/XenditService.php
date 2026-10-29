<?php

declare(strict_types=1);

namespace Billas\Domain\PaymentGateway;

use RuntimeException;

final class XenditService extends BaseProvider
{
    public function name(): string
    {
        return 'xendit';
    }

    public function isConfigured(): bool
    {
        return $this->env->string('XENDIT_SECRET_KEY') !== '';
    }

    public function createTransaction(array $context): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Xendit belum dikonfigurasi.');
        }
        $expiresAt = (int) ($context['expires_at'] ?? time() + 900);
        $payload = [
            'external_id' => (string) $context['merchant_ref'],
            'type' => 'DYNAMIC',
            'qr_code_type' => 'QRIS',
            'amount' => (int) $context['amount'],
            'callback_url' => $context['callback_url'] ?? $this->callbackUrl(),
            'expires_at' => gmdate('Y-m-d\TH:i:s.000\Z', $expiresAt),
        ];
        $auth = base64_encode($this->env->string('XENDIT_SECRET_KEY') . ':');
        $response = $this->postJson('https://api.xendit.co/qr_codes', $payload, ['Authorization' => 'Basic ' . $auth]);
        if (empty($response['qr_string'])) {
            throw new RuntimeException('Xendit tidak mengembalikan qr_string.');
        }

        return [
            'reference' => (string) ($response['id'] ?? $context['merchant_ref']),
            'qr_string' => (string) $response['qr_string'],
            'checkout_url' => null,
            'expires_at' => isset($response['expires_at']) ? (int) strtotime((string) $response['expires_at']) : $expiresAt,
            'payload' => $response,
        ];
    }

    public function verifyWebhook(array $headers, string $rawBody): bool
    {
        $token = $headers['x-callback-token'] ?? '';
        $expected = $this->env->string('XENDIT_WEBHOOK_TOKEN');
        if ($expected === '' || $token === '') {
            return false;
        }

        return hash_equals($expected, $token);
    }

    public function normalizeWebhook(array $headers, string $rawBody): array
    {
        $data = json_decode($rawBody, true);
        if (!is_array($data)) {
            throw new RuntimeException('Payload Xendit tidak valid.');
        }
        $status = strtoupper((string) ($data['status'] ?? ''));
        $mapped = match ($status) {
            'SUCCEEDED', 'PAID', 'COMPLETED' => 'paid',
            'EXPIRED' => 'expired',
            'FAILED' => 'failed',
            default => 'pending',
        };
        $externalId = (string) ($data['external_id'] ?? '');
        $id = (string) ($data['id'] ?? $data['qr_id'] ?? '');

        return [
            'merchant_ref' => $externalId !== '' ? $externalId : null,
            'external_ref' => null,
            'amount' => $this->amountInt($data['amount'] ?? 0),
            'status' => $mapped,
            'gateway_reference' => $id !== '' ? $id : null,
            'paid_at' => isset($data['updated']) ? (int) strtotime((string) $data['updated']) : null,
            'event_key' => 'xendit:' . ($id !== '' ? $id : $externalId) . ':' . $status,
            'kind' => 'auto',
        ];
    }

    public function supportsPersistent(string $accountType = 'va'): bool
    {
        if ($accountType === 'va') {
            return $this->isConfigured();
        }

        return $accountType === 'qris' && $this->env->bool('QRIS_STATIC_ENABLED', true);
    }

    public function buildSimulatedWebhook(array $target, string $event, ?int $amountOverride = null): array
    {
        $externalId = $this->simulatedRef($target);
        $amount = $this->simulatedAmount($target, $amountOverride);
        $body = [
            'id' => 'SIM-' . strtoupper(bin2hex(random_bytes(4))),
            'external_id' => $externalId,
            'status' => match ($event) {
                'PAID' => 'SUCCEEDED',
                'EXPIRED' => 'EXPIRED',
                default => 'FAILED',
            },
            'amount' => $amount,
            'payment_method' => 'QRIS',
            'updated' => gmdate('Y-m-d\TH:i:s.000\Z'),
        ];

        return [
            'body' => $body,
            'headers' => ['X-Callback-Token' => $this->env->string('XENDIT_WEBHOOK_TOKEN')],
            'body_format' => 'json',
        ];
    }

    public function createPersistentAccount(array $context): array
    {
        $accountType = (string) ($context['account_type'] ?? 'va');
        if ($accountType !== 'va') {
            return parent::createPersistentAccount($context);
        }
        if (!$this->isConfigured()) {
            throw new RuntimeException('Xendit belum dikonfigurasi.');
        }
        $customer = $context['customer'] ?? [];
        $customerId = (int) ($customer['id'] ?? 0);
        $externalId = 'BILLAS-VA-' . preg_replace('/[^A-Za-z0-9]/', '', (string) ($customer['code'] ?? (string) $customerId));
        $payload = [
            'external_id' => $externalId,
            'bank_code' => $this->env->string('XENDIT_VA_BANK', 'BCA'),
            'name' => mb_substr((string) ($customer['name'] ?? 'Pelanggan Billas'), 0, 50),
            'is_closed' => false,
            'is_single_use' => false,
        ];
        $auth = base64_encode($this->env->string('XENDIT_SECRET_KEY') . ':');
        $response = $this->postJson('https://api.xendit.co/callback_virtual_accounts', $payload, [
            'Authorization' => 'Basic ' . $auth,
        ]);
        if (empty($response['account_number'])) {
            throw new RuntimeException('Xendit tidak mengembalikan nomor VA.');
        }

        return [
            'external_ref' => (string) ($response['id'] ?? $externalId),
            'qr_string' => null,
            'payload' => [
                'mode' => 'va',
                'bank' => $payload['bank_code'],
                'account_number' => (string) $response['account_number'],
                'external_id' => $externalId,
            ],
        ];
    }
}
