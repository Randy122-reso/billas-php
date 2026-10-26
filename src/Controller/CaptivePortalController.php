<?php

declare(strict_types=1);

namespace Billas\Controller;

use Billas\Domain\CaptivePortalService;
use Billas\Domain\OrderService;
use Billas\Domain\PackageService;
use Billas\Domain\PaymentService;
use Billas\Domain\RouterService;
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

/**
 * Fitur E: zero-login captive portal for SSID RTRWNET. The client is identified
 * by router + SSID + client IP/MAC and a signed session token.
 */
final class CaptivePortalController extends AbstractController
{
    public function __construct(
        View $view,
        Session $session,
        Settings $settings,
        private readonly PackageService $packages,
        private readonly RouterService $routers,
        private readonly CaptivePortalService $captive,
        private readonly PaymentService $payments,
        private readonly OrderService $orders,
        private readonly VoucherService $vouchers,
    ) {
        parent::__construct($view, $session, $settings);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        if (!$this->settings->feature('wifi_captive_portal')) {
            return $this->render('error.php', [
                'pageTitle' => 'Captive Portal Nonaktif',
                'status' => 403,
                'message' => 'Captive portal WiFi sedang dinonaktifkan oleh operator.',
            ]);
        }

        $routerId = (int) $this->queryString($request, 'router_id', '0');
        $router = $routerId > 0 ? $this->routers->find($routerId) : null;
        if ($router === null) {
            $routerId = (int) ($this->routers->defaultId() ?? 0);
            $router = $routerId > 0 ? $this->routers->find($routerId) : null;
        }
        if ($router === null || (int) $router['active'] !== 1) {
            return $this->render('error.php', [
                'pageTitle' => 'Router Tidak Ditemukan',
                'status' => 404,
                'message' => 'Router captive portal tidak ditemukan atau tidak aktif. Hubungi operator WiFi.',
            ]);
        }

        $ssid = $this->queryString($request, 'ssid', $this->settings->ssid());
        if (!in_array($ssid, $this->settings->allowedSsids(), true)) {
            return $this->render('error.php', [
                'pageTitle' => 'SSID Tidak Diizinkan',
                'status' => 403,
                'message' => 'SSID ' . $ssid . ' tidak diizinkan untuk captive portal.',
            ]);
        }

        $clientIp = $this->queryString($request, 'client_ip', '');
        if ($clientIp === '' || filter_var($clientIp, FILTER_VALIDATE_IP) === false) {
            $clientIp = Http::ip($request);
        }
        $clientMac = Http::clientMac($this->queryString($request, 'client_mac', '') ?: null);
        $session = $this->captive->createSession($routerId, $ssid, $clientIp, $clientMac);

        return $this->render('wifi/portal.php', [
            'pageTitle' => $this->settings->brand() . ' WiFi',
            'brand' => $this->settings->brand(),
            'logo_url' => $this->settings->string('wifi.logo_url'),
            'terms_url' => $this->settings->string('wifi.terms_url'),
            'ssid' => $ssid,
            'router' => $router,
            'client_ip' => $clientIp,
            'client_mac' => $clientMac,
            'session_token' => $session['session_token'],
            'session_expires_at' => $session['expires_at'],
            'packages' => $this->packages->captivePackages(),
        ], 'wifi/layout');
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        /** @var array<string,mixed>|null $session */
        $session = $request->getAttribute('captive_session');
        $token = (string) $request->getAttribute('captive_token', '');
        if ($session === null) {
            return JsonResponse::error('Sesi captive portal tidak valid.', 401);
        }

        $packageId = $this->postInt($request, 'package_id');
        $package = $packageId > 0 ? $this->packages->find($packageId) : null;
        if (
            $package === null
            || $package['type'] !== 'hotspot'
            || (int) $package['active'] !== 1
            || (int) $package['available_on_captive'] !== 1
        ) {
            return JsonResponse::error('Paket WiFi tidak tersedia.', 422);
        }

        $routerId = (int) $session['router_id'];
        $router = $this->routers->find($routerId);
        if ($router === null || (int) $router['active'] !== 1) {
            return JsonResponse::error('Router WiFi tidak aktif.', 422);
        }

        $mac = (string) ($session['client_mac'] ?? '');
        if ($mac !== '') {
            $max = $this->settings->int('wifi.max_pending_orders_per_mac', 2);
            if ($max > 0 && $this->orders->pendingCountForMac($mac) >= $max) {
                return JsonResponse::error('Terlalu banyak pembayaran tertunda untuk perangkat ini. Selesaikan atau tunggu beberapa menit.', 429);
            }
        }

        $preferred = $this->postString($request, 'gateway');
        $context = [
            'source' => 'captive_portal',
            'ssid' => (string) $session['ssid'],
            'router_id' => $routerId,
            'client_ip' => (string) ($session['client_ip'] ?? Http::ip($request)),
            'client_mac' => $session['client_mac'] ?? null,
            'session_token' => $token,
            'customer_name' => 'Tamu ' . $this->settings->brand(),
        ];

        try {
            $order = $this->payments->createOrderPayment($package, $context, $preferred !== '' ? $preferred : null);
        } catch (Throwable $e) {
            return JsonResponse::error($e->getMessage(), 422);
        }

        $payload = billas_order_payload($order['gateway_payload'] ?? null);
        $qr = (string) ($order['gateway_qr_string'] ?? '');
        if ($qr === '' && isset($payload['qr_string'])) {
            $qr = (string) $payload['qr_string'];
        }

        return JsonResponse::ok([
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
            'checkout_url' => $payload['checkout_url'] ?? $payload['invoice_url'] ?? null,
            'status_url' => '/api/wifi/status/' . rawurlencode((string) $order['id']),
            'voucher_url' => '/wifi/voucher/' . rawurlencode((string) $order['id']),
        ]);
    }

    public function status(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $orderId = (string) ($args['orderId'] ?? '');
        $order = $this->orders->find($orderId);
        if ($order === null || ($order['source'] ?? '') !== 'captive_portal') {
            return JsonResponse::error('Order captive tidak ditemukan.', 404);
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
            'voucher_url' => $voucher !== null ? '/wifi/voucher/' . rawurlencode($orderId) : null,
            'server_time' => Time::now(),
        ]);
    }

    public function voucher(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $orderId = (string) ($args['orderId'] ?? '');
        $order = $this->orders->findWithPackage($orderId);
        if ($order === null) {
            return $this->render('error.php', [
                'pageTitle' => 'Voucher WiFi',
                'status' => 404,
                'message' => 'Order WiFi tidak ditemukan.',
            ]);
        }
        if (($order['source'] ?? '') !== 'captive_portal') {
            return $this->redirect('/voucher/' . rawurlencode($orderId));
        }

        $voucher = $this->vouchers->findByOrderId($orderId);
        if ($voucher === null) {
            $payload = billas_order_payload($order['gateway_payload'] ?? null);
            $qr = (string) ($order['gateway_qr_string'] ?? ($payload['qr_string'] ?? ''));

            return $this->render('wifi/qris.php', [
                'pageTitle' => 'Pembayaran WiFi',
                'brand' => $this->settings->brand(),
                'order' => $order,
                'payload' => $payload,
                'qr_data_uri' => billas_qr_data_uri($qr),
                'status_url' => '/api/wifi/status/' . rawurlencode($orderId),
                'voucher_url' => '/wifi/voucher/' . rawurlencode($orderId),
            ], 'wifi/layout');
        }

        if ((int) $voucher['revoked'] === 1) {
            return $this->render('error.php', [
                'pageTitle' => 'Password WiFi Kedaluwarsa',
                'status' => 410,
                'message' => 'Masa berlaku password WiFi ini sudah habis. Silakan kembali ke portal dan beli paket baru.',
            ]);
        }

        return $this->render('wifi/voucher.php', [
            'pageTitle' => 'Password WiFi',
            'brand' => $this->settings->brand(),
            'order' => $order,
            'voucher' => $voucher,
            'ssid' => $this->settings->ssid(),
        ], 'wifi/layout');
    }
}
