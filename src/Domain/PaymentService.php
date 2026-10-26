<?php

declare(strict_types=1);

namespace Billas\Domain;

use Billas\Domain\PaymentGateway\GatewayFactory;
use Billas\Domain\PaymentGateway\PaymentProvider;
use Billas\Support\Env;
use Billas\Support\Time;
use PDO;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class PaymentService
{
    public function __construct(
        private readonly PDO $db,
        private readonly Env $env,
        private readonly LoggerInterface $logger,
        private readonly GatewayFactory $gateways,
        private readonly OrderService $orders,
        private readonly UniqueCodeService $uniqueCodes,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * @param array<string,mixed> $package
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function createOrderPayment(array $package, array $context = [], ?string $preferredGateway = null): array
    {
        if (($package['type'] ?? '') !== 'hotspot') {
            throw new RuntimeException('Portal publik hanya untuk paket hotspot.');
        }

        $chain = $this->gateways->chain($preferredGateway);
        if ($chain === []) {
            throw new RuntimeException('Tidak ada gateway pembayaran yang terkonfigurasi.');
        }

        $lastError = null;
        foreach ($chain as $provider) {
            $merchantRef = $this->merchantRef();
            $baseAmount = (int) $package['price'];
            $amount = $baseAmount;
            if ($provider->name() === 'qris_static' && $this->env->bool('QRIS_STATIC_UNIQUE_CODE', true)) {
                $amount = $this->uniqueCodes->forPayment($baseAmount);
            }
            $expiresAt = Time::now() + $this->env->int('PAYMENT_ORDER_TTL', 900);

            try {
                $payment = $provider->createTransaction($this->providerContext(
                    $provider,
                    $merchantRef,
                    $amount,
                    $baseAmount,
                    'Voucher ' . $package['name'],
                    $package,
                    $context,
                    $expiresAt
                ));

                $orderContext = [
                    'client_ip' => $context['client_ip'] ?? null,
                    'client_mac' => $context['client_mac'] ?? null,
                ];
                if (($context['source'] ?? 'web_portal') === 'captive_portal') {
                    $orderContext += [
                        'source' => 'captive_portal',
                        'ssid' => $context['ssid'] ?? null,
                        'router_id' => $context['router_id'] ?? null,
                        'session_token' => $context['session_token'] ?? null,
                    ];
                    $order = $this->orders->createCaptiveOrder(
                        $package,
                        $provider->name(),
                        $amount,
                        $payment,
                        $orderContext,
                        $merchantRef
                    );
                } else {
                    $order = $this->orders->createWebOrder(
                        $package,
                        $provider->name(),
                        $amount,
                        $payment,
                        $orderContext,
                        $merchantRef
                    );
                }
                $this->audit->record('system', 'portal', 'order.payment_created', 'order', (string) $order['id'], [
                    'gateway' => $provider->name(),
                    'amount' => $amount,
                ], $context['client_ip'] ?? null);

                return $order + ['_gateway' => $provider->name()];
            } catch (Throwable $e) {
                $lastError = $e;
                $this->logger->warning('Gateway order gagal, mencoba fallback', [
                    'gateway' => $provider->name(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        throw new RuntimeException('Semua gateway gagal: ' . ($lastError?->getMessage() ?? 'unknown'));
    }

    /**
     * @param array<string,mixed> $invoice raw invoice row
     * @param array<string,mixed> $customer
     * @return array{transaction:array<string,mixed>,gateway:string}
     */
    public function createInvoicePayment(array $invoice, array $customer, ?string $preferredGateway = null): array
    {
        if (($invoice['status'] ?? '') !== 'unpaid') {
            throw new RuntimeException('Invoice tidak dapat dibayar.');
        }
        $chain = $this->gateways->chain($preferredGateway);
        if ($chain === []) {
            throw new RuntimeException('Tidak ada gateway pembayaran yang terkonfigurasi.');
        }

        $lastError = null;
        foreach ($chain as $provider) {
            $merchantRef = $this->merchantRef();
            $baseAmount = (int) $invoice['amount'];
            $amount = $baseAmount;
            if ($provider->name() === 'qris_static' && $this->env->bool('QRIS_STATIC_UNIQUE_CODE', true)) {
                $amount = $this->uniqueCodes->forPayment($baseAmount);
            }
            $expiresAt = Time::now() + $this->env->int('PAYMENT_ORDER_TTL', 900);

            try {
                $payment = $provider->createTransaction($this->providerContext(
                    $provider,
                    $merchantRef,
                    $amount,
                    $baseAmount,
                    'Tagihan ' . $invoice['invoice_no'],
                    null,
                    [
                        'customer_name' => $customer['name'] ?? 'Pelanggan',
                        'customer_email' => $customer['email'] ?? null,
                        'customer_phone' => $customer['phone'] ?? null,
                        'customer_id' => $customer['id'] ?? null,
                    ],
                    $expiresAt
                ));

                $payload = $payment['payload'] ?? [];
                $stmt = $this->db->prepare(
                    'INSERT INTO payment_transactions
                     (invoice_id, gateway, merchant_ref, gateway_reference, amount, status, qr_string, payload,
                      expires_at, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, "pending", ?, ?, ?, ?, ?)'
                );
                $now = Time::now();
                $stmt->execute([
                    (int) $invoice['id'],
                    $provider->name(),
                    $merchantRef,
                    $payment['reference'] ?? null,
                    $amount,
                    $payment['qr_string'] ?? null,
                    is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    (int) ($payment['expires_at'] ?? $expiresAt),
                    $now,
                    $now,
                ]);
                $transactionId = (int) $this->db->lastInsertId();
                $this->audit->record('customer', (string) ($customer['id'] ?? 'unknown'), 'invoice.payment_created', 'payment', (string) $transactionId, [
                    'invoice_id' => $invoice['id'],
                    'gateway' => $provider->name(),
                    'amount' => $amount,
                ]);

                return [
                    'transaction' => $this->invoiceTransaction($transactionId),
                    'gateway' => $provider->name(),
                ];
            } catch (Throwable $e) {
                $lastError = $e;
                $this->logger->warning('Gateway invoice gagal, mencoba fallback', [
                    'gateway' => $provider->name(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        throw new RuntimeException('Semua gateway gagal: ' . ($lastError?->getMessage() ?? 'unknown'));
    }

    /** @return array<string,mixed> */
    public function invoiceTransaction(int $id): array
    {
        $stmt = $this->db->prepare(
            'SELECT t.*, i.invoice_no, i.status AS invoice_status, i.period_end, c.name AS customer_name, c.code AS customer_code
             FROM payment_transactions t
             JOIN invoices i ON i.id = t.invoice_id
             JOIN customers c ON c.id = i.customer_id
             WHERE t.id = ?'
        );
        $stmt->execute([$id]);

        return $stmt->fetch() ?: [];
    }

    /** @param array<string,mixed> $package */
    private function providerContext(
        PaymentProvider $provider,
        string $merchantRef,
        int $amount,
        int $baseAmount,
        string $description,
        ?array $package,
        array $context,
        int $expiresAt,
    ): array {
        return [
            'merchant_ref' => $merchantRef,
            'amount' => $amount,
            'base_amount' => $baseAmount,
            'description' => $description,
            'customer_name' => (string) ($context['customer_name'] ?? 'Pelanggan Billas'),
            'customer_email' => (string) ($context['customer_email'] ?? 'noreply@billas.local'),
            'customer_phone' => (string) ($context['customer_phone'] ?? '0800000000'),
            'package_id' => $package['id'] ?? null,
            'package' => $package,
            'callback_url' => rtrim($this->env->string('APP_URL'), '/') . '/api/webhook/' . $provider->name(),
            'return_url' => rtrim($this->env->string('APP_URL'), '/'),
            'expires_at' => $expiresAt,
            'client_ip' => $context['client_ip'] ?? null,
        ];
    }

    private function merchantRef(): string
    {
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $ref = 'BLS-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
            $stmt = $this->db->prepare(
                'SELECT 1 FROM orders WHERE merchant_ref = ? UNION ALL SELECT 1 FROM payment_transactions WHERE merchant_ref = ? LIMIT 1'
            );
            $stmt->execute([$ref, $ref]);
            if (!$stmt->fetchColumn()) {
                return $ref;
            }
        }

        throw new RuntimeException('Tidak dapat membuat merchant reference unik.');
    }
}
