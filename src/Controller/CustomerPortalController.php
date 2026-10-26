<?php

declare(strict_types=1);

namespace Billas\Controller;

use Billas\Domain\AuditService;
use Billas\Domain\CustomerPaymentAccountService;
use Billas\Domain\CustomerService;
use Billas\Domain\InvoiceService;
use Billas\Domain\PaymentGateway\GatewayFactory;
use Billas\Domain\PaymentService;
use Billas\Domain\RateLimitService;
use Billas\Support\Http;
use Billas\Support\JsonResponse;
use Billas\Support\Session;
use Billas\Support\Settings;
use Billas\Support\Time;
use Billas\Support\View;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use Throwable;

/**
 * Customer PPPoE self-service portal: login, billing check (including the v4
 * service guarantee), invoice payment, persistent payment account and QRIS
 * unique.
 */
final class CustomerPortalController extends AbstractController
{
    public function __construct(
        View $view,
        Session $session,
        Settings $settings,
        private readonly PDO $db,
        private readonly CustomerService $customers,
        private readonly InvoiceService $invoices,
        private readonly PaymentService $payments,
        private readonly CustomerPaymentAccountService $accounts,
        private readonly GatewayFactory $gateways,
        private readonly RateLimitService $limits,
        private readonly AuditService $audit,
    ) {
        parent::__construct($view, $session, $settings);
    }

    public function loginForm(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        if ($this->session->customerId() !== null) {
            return $this->redirect('/portal');
        }

        return $this->render('customer/login.php', [
            'pageTitle' => 'Masuk Portal Pelanggan',
            'brand' => $this->settings->brand(),
        ], 'customer/layout');
    }

    public function login(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $identifier = $this->postString($request, 'identifier');
        $password = $this->postString($request, 'password');
        $ipLimit = $this->limits->hit('portal_login_ip', Http::ip($request), 10);
        $accountLimit = $this->limits->hit('portal_login_account', strtolower($identifier), 5);
        if (!$ipLimit['allowed'] || !$accountLimit['allowed']) {
            $this->session->flash('error', 'Terlalu banyak percobaan masuk. Coba lagi beberapa saat lagi.');

            return $this->redirect('/portal/login');
        }

        $customer = $identifier !== '' ? $this->customers->authenticate($identifier, $password) : null;
        if ($customer === null) {
            $this->audit->record('customer', $identifier !== '' ? $identifier : 'unknown', 'customer.login_failed', 'customer', null);
            $this->session->flash('error', 'Kode pelanggan/telepon atau password salah.');

            return $this->redirect('/portal/login');
        }

        $this->session->loginCustomer((int) $customer['id'], (string) $customer['code'], (string) $customer['name']);
        $this->audit->record('customer', (string) $customer['id'], 'customer.login', 'customer', (string) $customer['id']);
        $this->session->flash('success', 'Selamat datang, ' . $customer['name'] . '.');

        return $this->redirect('/portal/check');
    }

    public function logout(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $customerId = $this->session->customerId();
        $this->audit->record('customer', $customerId === null ? 'unknown' : (string) $customerId, 'customer.logout', 'customer', null);
        $this->session->logoutCustomer();
        $this->session->flash('success', 'Anda telah keluar dari portal.');

        return $this->redirect('/portal/login');
    }

    public function check(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $customer = $this->currentCustomer($request);
        $invoices = $this->invoices->forCustomer((int) $customer['id']);
        $openInvoice = $this->invoices->oldestOpen((int) $customer['id']);
        $guarantee = $this->guaranteeInfo($customer);

        return $this->render('customer/check_billing.php', [
            'pageTitle' => 'Cek Tagihan',
            'brand' => $this->settings->brand(),
            'customer' => $customer,
            'invoices' => $invoices,
            'open_invoice' => $openInvoice,
            'guarantee' => $guarantee,
            'qris_account' => $this->settings->feature('pppoe_qris_unique')
                ? $this->accounts->anyActiveQris((int) $customer['id'])
                : null,
        ], 'customer/layout');
    }

    public function invoice(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $customer = $this->currentCustomer($request);
        $invoice = $this->findCustomerInvoice($request, $customer, (int) ($args['id'] ?? 0));

        return $this->render('customer/invoice.php', [
            'pageTitle' => 'Invoice ' . $invoice['invoice_no'],
            'brand' => $this->settings->brand(),
            'customer' => $customer,
            'invoice' => $invoice,
            'transactions' => $this->transactionsForInvoice((int) $invoice['id']),
            'gateways' => $this->gateways->configuredNames(),
            'guarantee' => $this->guaranteeInfo($customer),
        ], 'customer/layout');
    }

    public function pay(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $customer = $this->currentCustomer($request);
        $invoice = $this->findCustomerInvoice($request, $customer, (int) ($args['id'] ?? 0));
        if ($invoice['status'] !== 'unpaid') {
            $this->session->flash('warning', 'Invoice ini tidak dapat dibayar (status: ' . $invoice['status'] . ').');

            return $this->redirect('/portal/invoice/' . (int) $invoice['id']);
        }

        $preferred = $this->postString($request, 'gateway');
        if ($preferred !== '' && !in_array($preferred, $this->gateways->configuredNames(), true)) {
            $preferred = '';
        }

        try {
            $result = $this->payments->createInvoicePayment($invoice, $customer, $preferred !== '' ? $preferred : null);
            $transaction = $result['transaction'];
            $this->session->flash('success', 'Pembayaran dibuat via ' . $result['gateway'] . '. Selesaikan sebelum batas waktu.');

            return $this->redirect('/portal/payment-status/' . (int) $transaction['id']);
        } catch (Throwable $e) {
            $this->session->flash('error', 'Gagal membuat pembayaran: ' . $e->getMessage());

            return $this->redirect('/portal/invoice/' . (int) $invoice['id']);
        }
    }

    public function paymentStatus(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $customer = $this->currentCustomer($request);
        $transaction = $this->findCustomerTransaction($request, $customer, (int) ($args['id'] ?? 0));
        $status = (string) $transaction['status'];
        $expired = $status === 'pending' && (int) $transaction['expires_at'] <= Time::now();
        $paid = $status === 'paid' || $transaction['invoice_status'] === 'paid';

        if ($this->wantsStatusJson($request)) {
            return JsonResponse::ok([
                'transaction' => [
                    'id' => (int) $transaction['id'],
                    'merchant_ref' => $transaction['merchant_ref'],
                    'gateway' => $transaction['gateway'],
                    'status' => $status,
                    'amount' => (int) $transaction['amount'],
                    'expires_at' => (int) $transaction['expires_at'],
                    'expired' => $expired,
                ],
                'invoice' => [
                    'id' => (int) $transaction['invoice_id'],
                    'invoice_no' => $transaction['invoice_no'],
                    'status' => $transaction['invoice_status'],
                    'period_end' => $transaction['period_end'],
                ],
                'paid' => $paid,
                'server_time' => Time::now(),
            ]);
        }

        $payload = billas_order_payload($transaction['payload'] ?? null);
        $qr = (string) ($transaction['qr_string'] ?? '');
        if ($qr === '' && isset($payload['qr_string'])) {
            $qr = (string) $payload['qr_string'];
        }
        $checkoutUrl = (string) ($payload['checkout_url'] ?? $payload['invoice_url'] ?? '');

        return $this->render('customer/payment_status.php', [
            'pageTitle' => 'Status Pembayaran',
            'brand' => $this->settings->brand(),
            'customer' => $customer,
            'transaction' => $transaction,
            'payload' => $payload,
            'qr_data_uri' => billas_qr_data_uri($qr),
            'checkout_url' => $checkoutUrl !== '' ? $checkoutUrl : null,
            'paid' => $paid,
            'expired' => $expired,
            'status_url' => '/portal/payment-status/' . (int) $transaction['id'],
            'invoice_url' => '/portal/invoice/' . (int) $transaction['invoice_id'],
        ], 'customer/layout');
    }

    public function account(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $customer = $this->currentCustomer($request);
        $accounts = $this->accounts->allForCustomer((int) $customer['id']);

        return $this->render('customer/account.php', [
            'pageTitle' => 'Akun Saya',
            'brand' => $this->settings->brand(),
            'customer' => $customer,
            'accounts' => $accounts,
            'qris_account' => $this->settings->feature('pppoe_qris_unique')
                ? $this->accounts->anyActiveQris((int) $customer['id'])
                : null,
            'guarantee' => $this->guaranteeInfo($customer),
            'persistent_enabled' => $this->settings->feature('persistent_account'),
        ], 'customer/layout');
    }

    public function qrisUnique(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        if (!$this->settings->feature('pppoe_qris_unique')) {
            return $this->render('error.php', [
                'pageTitle' => 'QRIS Unik Tidak Aktif',
                'status' => 403,
                'message' => 'Fitur QRIS unik sedang dinonaktifkan oleh operator.',
            ], 'customer/layout');
        }

        $customer = $this->currentCustomer($request);
        $account = $this->accounts->anyActiveQris((int) $customer['id']);

        return $this->render('customer/qris_unique.php', [
            'pageTitle' => 'QRIS Unik',
            'brand' => $this->settings->brand(),
            'customer' => $customer,
            'account' => $account,
            'guarantee' => $this->guaranteeInfo($customer),
            'open_invoice' => $this->invoices->oldestOpen((int) $customer['id']),
        ], 'customer/layout');
    }

    public function qrisUniqueCreate(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        if (!$this->settings->feature('pppoe_qris_unique')) {
            return $this->render('error.php', [
                'pageTitle' => 'QRIS Unik Tidak Aktif',
                'status' => 403,
                'message' => 'Fitur QRIS unik sedang dinonaktifkan oleh operator.',
            ], 'customer/layout');
        }

        $customer = $this->currentCustomer($request);
        try {
            $account = $this->accounts->getOrCreateQris((int) $customer['id']);
            $this->session->flash('success', 'QRIS unik siap digunakan melalui ' . $account['gateway'] . '.');
        } catch (Throwable $e) {
            $this->session->flash('error', 'QRIS unik belum dapat dibuat: ' . $e->getMessage());
        }

        return $this->redirect('/portal/qris-unique');
    }

    /** @return array<string,mixed> */
    private function currentCustomer(ServerRequestInterface $request): array
    {
        $customerId = $this->session->customerId();
        $customer = $customerId === null ? null : $this->customers->find($customerId);
        if ($customer === null) {
            $this->session->logoutCustomer();
            throw new HttpNotFoundException($request);
        }

        return $customer;
    }

    /**
     * @param array<string,mixed> $customer
     * @return array<string,mixed>
     */
    private function findCustomerInvoice(ServerRequestInterface $request, array $customer, int $invoiceId): array
    {
        $invoice = $this->invoices->find($invoiceId);
        if ($invoice === null || (int) $invoice['customer_id'] !== (int) $customer['id']) {
            throw new HttpNotFoundException($request);
        }

        return $invoice;
    }

    /**
     * @param array<string,mixed> $customer
     * @return array<string,mixed>
     */
    private function findCustomerTransaction(ServerRequestInterface $request, array $customer, int $transactionId): array
    {
        $stmt = $this->db->prepare(
            'SELECT t.*, i.invoice_no, i.status AS invoice_status, i.period_end, i.customer_id
             FROM payment_transactions t
             JOIN invoices i ON i.id = t.invoice_id
             WHERE t.id = ? AND i.customer_id = ?
             LIMIT 1'
        );
        $stmt->execute([$transactionId, (int) $customer['id']]);
        $transaction = $stmt->fetch();
        if ($transaction === false) {
            throw new HttpNotFoundException($request);
        }

        return $transaction;
    }

    /** @return list<array<string,mixed>> */
    private function transactionsForInvoice(int $invoiceId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM payment_transactions WHERE invoice_id = ? ORDER BY id DESC LIMIT 10'
        );
        $stmt->execute([$invoiceId]);

        return $stmt->fetchAll();
    }

    /**
     * @param array<string,mixed> $customer
     * @return array{until:?string,active:bool,days_left:int,label:string}
     */
    private function guaranteeInfo(array $customer): array
    {
        $until = $customer['service_guaranteed_until'] ?? null;
        if ($until === null || $until === '') {
            return ['until' => null, 'active' => false, 'days_left' => 0, 'label' => 'Belum ada garansi aktif'];
        }

        $today = Time::today();
        $daysLeft = (int) floor((strtotime((string) $until) - strtotime($today)) / 86400);
        $active = $until >= $today;

        return [
            'until' => (string) $until,
            'active' => $active,
            'days_left' => max(0, $daysLeft),
            'label' => $active
                ? 'Layanan dijamin sampai ' . $until . ' (' . max(0, $daysLeft) . ' hari lagi)'
                : 'Garansi layanan berakhir ' . $until,
        ];
    }

    private function wantsStatusJson(ServerRequestInterface $request): bool
    {
        return $this->queryString($request, 'format') === 'json'
            || Http::wantsJson($request)
            || str_contains($request->getHeaderLine('Accept'), 'application/json');
    }
}
