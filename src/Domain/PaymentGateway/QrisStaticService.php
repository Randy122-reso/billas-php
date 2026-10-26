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
