<?php

declare(strict_types=1);

namespace Billas\Controller;

use Billas\Domain\OrderService;
use Billas\Domain\PackageService;
use Billas\Domain\VoucherService;
use Billas\Support\JsonResponse;
use Billas\Support\Session;
use Billas\Support\Settings;
use Billas\Support\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/** Public hotspot portal: package list, QRIS page, voucher page. */
final class PagesController extends AbstractController
{
    public function __construct(
        View $view,
        Session $session,
        Settings $settings,
        private readonly PackageService $packages,
        private readonly OrderService $orders,
        private readonly VoucherService $vouchers,
    ) {
        parent::__construct($view, $session, $settings);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        return $this->render('portal.php', [
            'pageTitle' => 'Beli Voucher Hotspot',
            'packages' => $this->packages->all('hotspot', true),
            'brand' => $this->settings->brand(),
        ]);
    }

    public function voucher(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $orderId = (string) ($args['orderId'] ?? '');
        $order = $this->orders->findWithPackage($orderId);
        if ($order === null) {
            throw new HttpNotFoundException($request);
        }
        if (($order['source'] ?? 'web_portal') === 'captive_portal') {
            return $this->redirect('/wifi/voucher/' . rawurlencode($orderId));
        }

        $voucher = $this->vouchers->findByOrderId($orderId);
        if ($voucher !== null && (int) $voucher['revoked'] === 1) {
            return $this->render('error.php', [
                'pageTitle' => 'Voucher Tidak Aktif',
                'status' => 410,
                'message' => 'Voucher ini sudah kedaluwarsa atau dicabut.',
            ]);
        }

        if ($voucher !== null) {
            return $this->render('voucher.php', [
                'pageTitle' => 'Voucher Hotspot',
                'order' => $order,
                'voucher' => $voucher,
                'brand' => $this->settings->brand(),
            ]);
        }

        $payload = billas_order_payload($order['gateway_payload'] ?? null);
        $qr = (string) ($order['gateway_qr_string'] ?? '');
        if ($qr === '' && isset($payload['qr_string'])) {
            $qr = (string) $payload['qr_string'];
        }

        return $this->render('qris.php', [
            'pageTitle' => 'Pembayaran QRIS',
            'order' => $order,
            'payload' => $payload,
            'qr_string' => $qr,
            'qr_data_uri' => billas_qr_data_uri($qr),
            'status_url' => '/api/pay/status/' . rawurlencode($orderId),
            'redirect_url' => '/voucher/' . rawurlencode($orderId),
            'brand' => $this->settings->brand(),
        ]);
    }
}
