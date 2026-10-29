<?php

declare(strict_types=1);

namespace Billas\Domain\PaymentGateway;

use Billas\Domain\QrisUtil;
use RuntimeException;

/** Fallback QRIS statis EMVCo with manual reconciliation. */
final class QrisStaticService extends BaseProvider
{
    public function name(): string
    {
        return 'qris_static';
    }

    public function isConfigured(): bool
    {
        if (!$this->env->bool('QRIS_STATIC_ENABLED', true)) {
            return false;
        }

        return $this->env->string('QRIS_STATIC_PAYLOAD') !== ''
            || $this->env->string('QRIS_STATIC_MERCHANT_ID') !== ''
            || $this->env->isLocal();
    }

    public function createTransaction(array $context): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('QRIS statis belum dikonfigurasi.');
        }
        $amount = (int) $context['amount'];
        $baseAmount = (int) ($context['base_amount'] ?? $amount);
        [$qr, $demo] = $this->qrForAmount($amount);

        return [
            'reference' => (string) $context['merchant_ref'],
            'qr_string' => $qr,
            'checkout_url' => null,
            'expires_at' => (int) ($context['expires_at'] ?? time() + 900),
            'payload' => [
                'mode' => 'static',
                'demo' => $demo,
                'base_amount' => $baseAmount,
                'payable_amount' => $amount,
                'unique_code' => max(0, $amount - $baseAmount),
                'note' => 'Bayar dengan nominal tepat. Konfirmasi manual oleh admin.',
            ],
        ];
    }

    public function verifyWebhook(array $headers, string $rawBody): bool
    {
        return false;
    }

    public function normalizeWebhook(array $headers, string $rawBody): array
    {
        throw new RuntimeException('QRIS statis tidak memiliki webhook; gunakan rekonsiliasi admin.');
    }

    public function createPersistentAccount(array $context): array
    {
        $customer = $context['customer'] ?? [];
        $customerId = (int) ($customer['id'] ?? 0);
        $code = preg_replace('/[^A-Za-z0-9]/', '', (string) ($customer['code'] ?? 'CUS' . $customerId));
        [$qr, $demo] = $this->qrForAmount(null);

        return [
            'external_ref' => 'QRISSTATIC-' . strtoupper($code),
            'qr_string' => $qr,
            'payload' => [
                'mode' => 'static',
                'demo' => $demo,
                'note' => 'QRIS statis dapat dipakai berulang; nominal dibayar manual dan dicocokkan admin.',
            ],
        ];
    }

    public function supportsPersistent(string $accountType = 'qris'): bool
    {
        return $accountType === 'qris';
    }

    /**
     * QRIS statis has no gateway callback, so the simulator uses a synthetic
     * payload. It is only valid in bypass mode (the admin UI/service rejects
     * sign mode because there is no signature to verify).
     */
    public function buildSimulatedWebhook(array $target, string $event, ?int $amountOverride = null): array
    {
        $merchantRef = $this->simulatedRef($target);
        $amount = $this->simulatedAmount($target, $amountOverride);
        $body = [
            'simulated' => true,
            'status' => $event,
            'merchant_ref' => $merchantRef,
            'external_ref' => (string) ($target['external_ref'] ?? $target['qris_unique_ref'] ?? $merchantRef),
            'amount' => $amount,
            'reference' => 'SIM-' . strtoupper(bin2hex(random_bytes(4))),
        ];

        return [
            'body' => $body,
            'headers' => [],
            'body_format' => 'json',
        ];
    }

    public function normalizeSimulatedWebhook(array $headers, string $rawBody): array
    {
        $data = json_decode($rawBody, true);
        if (!is_array($data)) {
            throw new RuntimeException('Payload simulasi QRIS statis tidak valid.');
        }
        $status = strtolower((string) ($data['status'] ?? ''));
        if (!in_array($status, ['paid', 'expired', 'failed'], true)) {
            $status = 'pending';
        }
        $merchantRef = (string) ($data['merchant_ref'] ?? '');
        $externalRef = (string) ($data['external_ref'] ?? '');
        $reference = (string) ($data['reference'] ?? '');

        return [
            'merchant_ref' => $merchantRef !== '' ? $merchantRef : null,
            'external_ref' => $externalRef !== '' ? $externalRef : null,
            'amount' => $this->amountInt($data['amount'] ?? 0),
            'status' => $status,
            'gateway_reference' => $reference !== '' ? $reference : null,
            'paid_at' => $status === 'paid' ? time() : null,
            'event_key' => 'qris_static:' . ($reference !== '' ? $reference : $merchantRef) . ':' . strtoupper($status),
            'kind' => 'auto',
        ];
    }

    /** @return array{0:string,1:bool} */
    private function qrForAmount(?int $amount): array
    {
        $payload = $this->env->string('QRIS_STATIC_PAYLOAD');
        $demo = $payload === '';
        if ($demo) {
            $payload = QrisUtil::demoPayload(
                $this->env->string('QRIS_STATIC_MERCHANT_ID'),
                $this->env->string('QRIS_STATIC_MERCHANT_NAME'),
                $this->env->string('QRIS_STATIC_CITY'),
                $this->env->string('QRIS_STATIC_POSTAL')
            );
        }

        return [$amount === null ? QrisUtil::withoutAmount($payload) : QrisUtil::withAmount($payload, $amount), $demo];
    }
}
