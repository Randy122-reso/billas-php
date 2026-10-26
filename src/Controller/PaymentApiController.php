<?php

declare(strict_types=1);

namespace Billas\Controller;

use Billas\Domain\OrderService;
use Billas\Domain\PackageService;
use Billas\Domain\PaymentService;
use Billas\Domain\VoucherService;
use Billas\Support\Http;
use Billas\Support\JsonResponse;
use Billas\Support\Session;
use Billas\Support\Settings;
use Billas\Support\Time;
use Billas\Support\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/** Public hotspot order API. */
final class PaymentApiController extends AbstractController
{
    public function __construct(
        View $view,
        Session $session,
        Settings $settings,
        private readonly PackageService $packages,
        private readonly PaymentService $payments,
        private readonly OrderService $orders,
        private readonly VoucherService $vouchers,
    ) {
        parent::__construct($view, $session, $settings);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $packageId = $this->inputInt($request, 'package_id');
        $package = $packageId > 0 ? $this->packages->find($packageId) : null;
        if ($package === null || (int) $package['active'] !== 1 || $package['type'] !== 'hotspot') {
            return JsonResponse::error('Paket hotspot tidak ditemukan atau tidak aktif.', 422);
        }

        $preferred = $this->postString($request, 'gateway');
        $context = [
            'client_ip' => Http::ip($request),
            'client_mac' => Http::clientMac($this->postString($request, 'client_mac')),
            'customer_name' => 'Pelanggan Hotspot',
        ];

        try {
            $order = $this->payments->createOrderPayment($package, $context, $preferred !== '' ? $preferred : null);
        } catch (Throwable $e) {
            return JsonResponse::error($e->getMessage(), 422);
        }

        return JsonResponse::ok($this->orderPayload($order, $this->settings->brand()));
    }

    public function status(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $orderId = (string) ($args['orderId'] ?? '');
        $order = $this->orders->find($orderId);
        if ($order === null) {
            return JsonResponse::error('Order tidak ditemukan.', 404);
        }

        $voucher = $this->vouchers->findByOrderId($orderId);
        $expired = $order['status'] === 'pending' && (int) $order['expires_at'] <= Time::now();

        return JsonResponse::ok([
            'order' => [
                'id' => $order['id'],
                'merchant_ref' => $order['merchant_ref'],
                'status' => $order['status'],
                'amount' => (int) $order['amount'],
                'expires_at' => (int) $order['expires_at'],
                'expired' => $expired,
            ],
            'paid' => $order['status'] === 'paid',
            'voucher_ready' => $voucher !== null,
            'voucher_url' => $voucher !== null ? '/voucher/' . rawurlencode($orderId) : null,
            'server_time' => Time::now(),
        ]);
    }

    /** @param array<string,mixed> $order */
    private function orderPayload(array $order, string $brand): array
    {
        $payload = billas_order_payload($order['gateway_payload'] ?? null);
        $qr = (string) ($order['gateway_qr_string'] ?? '');
        if ($qr === '' && isset($payload['qr_string'])) {
            $qr = (string) $payload['qr_string'];
        }
        $checkoutUrl = (string) ($payload['checkout_url'] ?? $payload['invoice_url'] ?? '');

        return [
            'order' => [
                'id' => $order['id'],
                'merchant_ref' => $order['merchant_ref'],
                'amount' => (int) $order['amount'],
                'gateway' => $order['gateway'],
                'status' => $order['status'],
                'expires_at' => (int) $order['expires_at'],
            ],
            'qr_string' => $qr,
            'qr_data_uri' => billas_qr_data_uri($qr),
            'checkout_url' => $checkoutUrl !== '' ? $checkoutUrl : null,
            'redirect_url' => '/voucher/' . rawurlencode((string) $order['id']),
            'brand' => $brand,
        ];
    }

    private function inputInt(ServerRequestInterface $request, string $key): int
    {
        $value = $this->input($request)[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }
}
