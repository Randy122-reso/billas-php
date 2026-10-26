<?php

declare(strict_types=1);

namespace Billas\Controller;

use Billas\Domain\AnalyticsService;
use Billas\Domain\AuditService;
use Billas\Domain\CustomerPaymentAccountService;
use Billas\Domain\CustomerService;
use Billas\Domain\InvoiceService;
use Billas\Domain\MikrotikService;
use Billas\Domain\PackageService;
use Billas\Domain\RateLimitService;
use Billas\Domain\ReconciliationService;
use Billas\Domain\RouterService;
use Billas\Domain\VoucherService;
use Billas\Support\Env;
use Billas\Support\JsonResponse;
use Billas\Support\Session;
use Billas\Support\Settings;
use Billas\Support\Time;
use Billas\Support\View;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Throwable;

/** Admin back-office. Every mutation is a POST with CSRF protection. */
final class AdminPortalController extends AbstractController
{
    public function __construct(
        View $view,
        Session $session,
        Settings $settings,
        private readonly PDO $db,
        private readonly Env $env,
        private readonly CustomerService $customers,
        private readonly PackageService $packages,
        private readonly RouterService $routers,
        private readonly InvoiceService $invoices,
        private readonly VoucherService $vouchers,
        private readonly CustomerPaymentAccountService $accounts,
        private readonly AnalyticsService $analytics,
        private readonly ReconciliationService $reconciliation,
        private readonly MikrotikService $mikrotik,
        private readonly RateLimitService $limits,
        private readonly AuditService $audit,
    ) {
        parent::__construct($view, $session, $settings);
    }

    public function loginForm(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        if ($this->session->adminId() !== null) {
            return $this->redirect('/admin/dashboard');
        }

        return $this->render('admin/login.php', ['pageTitle' => 'Masuk Admin']);
    }

    public function login(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $username = $this->postString($request, 'username');
        $password = $this->postString($request, 'password');
        $accountLimit = $this->limits->hit('login_account', strtolower($username), 5);
        if (!$accountLimit['allowed']) {
            $this->session->flash('error', 'Terlalu banyak percobaan untuk akun ini. Coba lagi nanti.');

            return $this->redirect('/admin/login');
        }

        $stmt = $this->db->prepare('SELECT * FROM users WHERE username = ? AND active = 1 LIMIT 1');
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        if ($user === false || !password_verify($password, (string) $user['password_hash'])) {
            $this->session->flash('error', 'Username atau password salah.');
            $this->audit->record('admin', $username !== '' ? $username : 'unknown', 'admin.login_failed', 'user', null);

            return $this->redirect('/admin/login');
        }

        $this->session->loginAdmin((int) $user['id'], (string) $user['username'], (string) $user['role']);
        $this->audit->record('admin', (string) $user['username'], 'admin.login', 'user', (string) $user['id']);
        $this->session->flash('success', 'Selamat datang, ' . $user['username'] . '.');

        return $this->redirect('/admin/dashboard');
    }

    public function logout(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $this->audit->record('admin', $this->adminActor(), 'admin.logout', 'user', null);
        $this->session->logoutAdmin();
        $this->session->flash('success', 'Anda telah keluar.');

        return $this->redirect('/admin/login');
    }

    public function dashboard(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        return $this->render('admin/dashboard.php', [
            'pageTitle' => 'Dashboard',
            'summary' => $this->analytics->summary(),
            'orders' => $this->rows('SELECT o.*, p.name AS package_name FROM orders o JOIN packages p ON p.id = o.package_id ORDER BY o.created_at DESC LIMIT 10'),
            'vouchers' => $this->vouchers->listAdmin('', ''),
            'captive' => $this->rows("SELECT s.*, p.name AS package_name FROM captive_portal_sessions s LEFT JOIN packages p ON p.id = s.package_id ORDER BY s.id DESC LIMIT 8"),
            'jobs' => $this->rows('SELECT * FROM job_state ORDER BY task'),
            'retry' => $this->rows('SELECT status, COUNT(*) AS total FROM retry_queue GROUP BY status'),
        ], 'admin/layout');
    }

    public function customers(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $editId = (int) $this->queryString($request, 'edit', '0');
        $edit = $editId > 0 ? $this->customers->find($editId) : null;

        return $this->render('admin/customers.php', [
            'pageTitle' => 'Pelanggan',
            'customers' => $this->customers->all($this->queryString($request, 'status'), $this->queryString($request, 'q')),
            'packages' => $this->packages->all('pppoe'),
            'routers' => $this->routers->all(),
            'edit' => $edit,
            'accounts' => $edit !== null ? $this->accounts->allForCustomer((int) $edit['id']) : [],
            'filter_status' => $this->queryString($request, 'status'),
            'search' => $this->queryString($request, 'q'),
        ], 'admin/layout');
    }

    public function customersSave(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $id = $this->postInt($request, 'id');
        $data = [
            'code' => $this->postString($request, 'code'),
            'name' => $this->postString($request, 'name'),
            'phone' => $this->postString($request, 'phone'),
            'email' => $this->postString($request, 'email'),
            'pppoe_username' => $this->postString($request, 'pppoe_username'),
            'pppoe_password' => $this->postString($request, 'pppoe_password'),
            'package_id' => $this->postInt($request, 'package_id'),
            'router_id' => $this->postInt($request, 'router_id'),
            'status' => $this->postString($request, 'status', 'active'),
            'paid_until' => $this->postString($request, 'paid_until'),
            'service_guaranteed_until' => $this->postString($request, 'service_guaranteed_until'),
            'portal_password' => $this->postString($request, 'portal_password'),
            '_actor' => $this->adminActor(),
        ];

        try {
            if ($id > 0) {
                $this->customers->update($id, $data);
                $this->session->flash('success', 'Pelanggan diperbarui.');
                $customerId = $id;
            } else {
                $customerId = $this->customers->create($data);
                $this->session->flash('success', 'Pelanggan dibuat.');
                if ($this->settings->feature('pppoe_qris_unique') && $this->settings->bool('pppoe_qris.auto_create', true)) {
                    try {
                        $this->accounts->getOrCreateQris($customerId);
                        $this->session->flash('success', 'QRIS unik otomatis dibuat.');
                    } catch (Throwable $e) {
                        $this->session->flash('warning', 'QRIS unik belum dibuat: ' . $e->getMessage());
                    }
                }
            }
        } catch (Throwable $e) {
            $this->session->flash('error', $e->getMessage());

            return $this->redirect('/admin/customers' . ($id > 0 ? '?edit=' . $id : ''));
        }

        return $this->redirect('/admin/customers?edit=' . $customerId);
    }

    public function createQrisUnique(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $id = (int) ($args['id'] ?? 0);
        try {
            $account = $this->accounts->getOrCreateQris($id);
            $this->session->flash('success', 'QRIS unik siap digunakan (' . $account['gateway'] . ').');
        } catch (Throwable $e) {
            $this->session->flash('error', 'QRIS unik gagal dibuat: ' . $e->getMessage());
        }

        return $this->redirect('/admin/customers?edit=' . $id);
    }

    public function deactivateQrisUnique(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $id = (int) ($args['id'] ?? 0);
        $account = $this->accounts->anyActiveQris($id);
        if ($account !== null) {
            $this->accounts->deactivate((int) $account['id']);
            $this->session->flash('success', 'QRIS unik dinonaktifkan.');
        } else {
            $this->session->flash('warning', 'Tidak ada QRIS unik aktif.');
        }

        return $this->redirect('/admin/customers?edit=' . $id);
    }

    public function createPaymentAccount(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $id = (int) ($args['id'] ?? 0);
        $gateway = $this->postString($request, 'gateway', 'xendit');
        try {
            $account = $this->accounts->getOrCreate($id, $gateway, 'va');
            $this->session->flash('success', 'Akun pembayaran persisten dibuat (' . $account['gateway'] . ').');
        } catch (Throwable $e) {
            $this->session->flash('error', 'Akun pembayaran gagal dibuat: ' . $e->getMessage());
        }

        return $this->redirect('/admin/customers?edit=' . $id);
    }

    public function customerStatus(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $id = (int) ($args['id'] ?? 0);
        $status = $this->postString($request, 'status');
        try {
            $this->customers->setStatus($id, $status);
            if ($status === 'active') {
                $this->customers->reactivate($id);
            }
            $this->session->flash('success', 'Status pelanggan diperbarui.');
        } catch (Throwable $e) {
            $this->session->flash('error', $e->getMessage());
        }

        return $this->redirect('/admin/customers?edit=' . $id);
    }

    public function packages(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $editId = (int) $this->queryString($request, 'edit', '0');

        return $this->render('admin/packages.php', [
            'pageTitle' => 'Paket',
            'packages' => $this->packages->all(),
            'edit' => $editId > 0 ? $this->packages->find($editId) : null,
        ], 'admin/layout');
    }

    public function packagesSave(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $id = $this->postInt($request, 'id');
        $data = [
            'name' => $this->postString($request, 'name'),
            'type' => $this->postString($request, 'type', 'hotspot'),
            'price' => $this->postInt($request, 'price'),
            'duration_value' => $this->postString($request, 'duration_value'),
            'duration_unit' => $this->postString($request, 'duration_unit', 'days'),
            'duration_mode' => $this->postString($request, 'duration_mode', 'uptime'),
            'calendar_days' => $this->postString($request, 'calendar_days'),
            'mikrotik_profile' => $this->postString($request, 'mikrotik_profile'),
            'bandwidth_down' => $this->postString($request, 'bandwidth_down'),
            'bandwidth_up' => $this->postString($request, 'bandwidth_up'),
            'active' => $this->postString($request, 'active') !== '' || !$id,
            'available_on_captive' => $this->postString($request, 'available_on_captive') !== '',
        ];
        try {
            $saved = $this->packages->save($data, $id > 0 ? $id : null);
            $this->session->flash('success', 'Paket disimpan.');
            return $this->redirect('/admin/packages?edit=' . $saved);
        } catch (Throwable $e) {
            $this->session->flash('error', $e->getMessage());

            return $this->redirect('/admin/packages' . ($id > 0 ? '?edit=' . $id : ''));
        }
    }

    public function packageToggle(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $this->packages->toggle((int) ($args['id'] ?? 0));
        $this->session->flash('success', 'Status paket diperbarui.');

        return $this->redirect('/admin/packages');
    }

    public function routers(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $editId = (int) $this->queryString($request, 'edit', '0');

        return $this->render('admin/routers.php', [
            'pageTitle' => 'Router',
            'routers' => $this->routers->all(),
            'edit' => $editId > 0 ? $this->routers->find($editId) : null,
            'fake' => $this->env->bool('MIKROTIK_FAKE'),
        ], 'admin/layout');
    }

    public function routersSave(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $id = $this->postInt($request, 'id');
        $data = [
            'name' => $this->postString($request, 'name'),
            'host' => $this->postString($request, 'host'),
            'port' => $this->postInt($request, 'port', 8728),
            'username' => $this->postString($request, 'username'),
            'password' => $this->postString($request, 'password'),
            'use_tls' => $this->postString($request, 'use_tls') !== '',
            'active' => $this->postString($request, 'active') !== '' || !$id,
        ];
        try {
            $saved = $this->routers->save($data, $id > 0 ? $id : null);
            $this->session->flash('success', 'Router disimpan.');
            return $this->redirect('/admin/routers?edit=' . $saved);
        } catch (Throwable $e) {
            $this->session->flash('error', $e->getMessage());

            return $this->redirect('/admin/routers' . ($id > 0 ? '?edit=' . $id : ''));
        }
    }

    public function routerToggle(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $this->routers->toggle((int) ($args['id'] ?? 0));
        $this->session->flash('success', 'Status router diperbarui.');

        return $this->redirect('/admin/routers');
    }

    public function routerTest(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        try {
            $result = $this->mikrotik->testRouter((int) ($args['id'] ?? 0));
            $this->session->flash('success', 'Tes router berhasil: ' . $result);
        } catch (Throwable $e) {
            $this->session->flash('error', 'Tes router gagal: ' . $e->getMessage());
        }

        return $this->redirect('/admin/routers');
    }

    public function invoices(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        return $this->render('admin/billing.php', [
            'pageTitle' => 'Tagihan',
            'invoices' => $this->invoices->listAdmin($this->queryString($request, 'status'), $this->queryString($request, 'period')),
            'status' => $this->queryString($request, 'status'),
            'period' => $this->queryString($request, 'period'),
        ], 'admin/layout');
    }

    public function invoicesGenerate(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $period = $this->postString($request, 'period');
        try {
            $result = $this->invoices->generateMonthly($period !== '' ? $period : null);
            $this->session->flash('success', 'Invoice ' . $result['period'] . ': ' . $result['created'] . ' dibuat, ' . $result['skipped'] . ' dilewati.');
        } catch (Throwable $e) {
            $this->session->flash('error', 'Gagal membuat invoice: ' . $e->getMessage());
        }

        return $this->redirect('/admin/invoices');
    }

    public function invoiceMarkPaid(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        try {
            $this->invoices->markPaid((int) ($args['id'] ?? 0));
            $this->session->flash('success', 'Invoice ditandai lunas.');
        } catch (Throwable $e) {
            $this->session->flash('error', $e->getMessage());
        }

        return $this->redirect('/admin/invoices');
    }

    public function vouchers(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        return $this->render('admin/vouchers.php', [
            'pageTitle' => 'Voucher',
            'vouchers' => $this->vouchers->listAdmin($this->queryString($request, 'source'), $this->queryString($request, 'status')),
            'source' => $this->queryString($request, 'source'),
            'status' => $this->queryString($request, 'status'),
        ], 'admin/layout');
    }

    public function voucherRevoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $ok = $this->vouchers->revoke((int) ($args['id'] ?? 0), 'manual', $this->adminActor());
        $this->session->flash($ok ? 'success' : 'warning', $ok ? 'Voucher dicabut.' : 'Voucher sudah dicabut atau tidak ditemukan.');

        return $this->redirect('/admin/vouchers');
    }

    public function wifiSessions(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        return $this->render('admin/wifi_sessions.php', [
            'pageTitle' => 'Sesi WiFi',
            'sessions' => $this->rows(
                'SELECT s.*, p.name AS package_name, o.status AS order_status, o.amount,
                        v.code AS voucher_code, v.revoked AS voucher_revoked, v.expires_at AS voucher_expires_at
                 FROM captive_portal_sessions s
                 LEFT JOIN packages p ON p.id = s.package_id
                 LEFT JOIN orders o ON o.id = s.order_id
                 LEFT JOIN vouchers v ON v.id = s.voucher_id
                 WHERE (? = "" OR s.status = ?)
                 ORDER BY s.id DESC LIMIT 500',
                [$this->queryString($request, 'status'), $this->queryString($request, 'status')]
            ),
            'stats' => $this->rows('SELECT status, COUNT(*) AS total FROM captive_portal_sessions GROUP BY status'),
            'status' => $this->queryString($request, 'status'),
        ], 'admin/layout');
    }

    public function analytics(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        return $this->render('admin/analytics.php', [
            'pageTitle' => 'Analitik',
            'summary' => $this->analytics->summary(),
            'daily' => $this->analytics->dailySeries(14),
            'top_packages' => $this->analytics->topPackages(6),
            'captive_daily' => $this->analytics->captiveDaily(14),
        ], 'admin/layout');
    }

    public function analyticsCsv(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $days = max(1, min(90, (int) $this->queryString($request, 'days', '30')));
        $csv = $this->analytics->toCsv($days);
        $response = new Response();
        $response->getBody()->write($csv);

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="billas-analytics-' . date('Ymd') . '.csv"');
    }

    public function reconciliation(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        return $this->render('admin/reconciliation.php', $this->reconciliationData($request) + [
            'pageTitle' => 'Rekonsiliasi',
        ], 'admin/layout');
    }

    public function reconciliationConfirm(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $ref = $this->postString($request, 'merchant_ref');
        try {
            $result = $this->reconciliation->applyBatch([$ref], $this->adminActor(), true);
            $this->session->flash($result['failed'] > 0 ? 'warning' : 'success', 'Konfirmasi: ' . ($result['results'][0]['message'] ?? 'selesai'));
        } catch (Throwable $e) {
            $this->session->flash('error', 'Konfirmasi gagal: ' . $e->getMessage());
        }

        return $this->redirect('/admin/reconciliation');
    }

    public function reconciliationAccountConfirm(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $ref = $this->postString($request, 'external_ref');
        $amount = (int) preg_replace('/\D+/', '', $this->postString($request, 'amount'));
        $mutationRef = $this->postString($request, 'mutation_ref');
        try {
            $result = $this->reconciliation->confirmAccount(
                $ref,
                $amount,
                $this->adminActor(),
                $mutationRef !== '' ? $mutationRef : null
            );
            $status = ($result['status'] ?? '') === 'processed' || ($result['status'] ?? '') === 'duplicate'
                ? 'success'
                : 'warning';
            $this->session->flash($status, 'Konfirmasi QRIS unik: ' . ($result['message'] ?? 'selesai'));
        } catch (Throwable $e) {
            $this->session->flash('error', 'Konfirmasi QRIS unik gagal: ' . $e->getMessage());
        }

        return $this->redirect('/admin/reconciliation');
    }

    public function reconciliationBatch(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $text = $this->postString($request, 'refs_text');
        $refs = $this->postList($request, 'refs');
        foreach (preg_split('/[\r\n,]+/', $text) ?: [] as $line) {
            if (trim($line) !== '') {
                $refs[] = trim($line);
            }
        }
        $refs = array_values(array_unique(array_filter($refs)));

        if ($this->postString($request, 'mode') === 'preview') {
            return $this->render('admin/reconciliation.php', $this->reconciliationData($request) + [
                'pageTitle' => 'Rekonsiliasi',
                'preview' => $this->reconciliation->preview($refs),
                'preview_refs' => $refs,
            ], 'admin/layout');
        }

        $result = $this->reconciliation->applyBatch($refs, $this->adminActor(), true);
        $this->session->flash(
            $result['failed'] > 0 ? 'warning' : 'success',
            'Batch rekonsiliasi: ' . $result['applied'] . ' berhasil, ' . $result['failed'] . ' gagal.'
        );

        return $this->redirect('/admin/reconciliation');
    }

    public function settings(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $settings = $this->settings->all();
        $features = [];
        foreach (['hotspot_calendar_expiry', 'persistent_account', 'subscription_paid_until', 'pppoe_qris_unique', 'wifi_captive_portal'] as $name) {
            $features[$name] = $this->settings->feature($name);
        }

        return $this->render('admin/settings.php', [
            'pageTitle' => 'Pengaturan',
            'settings' => $settings,
            'features' => $features,
            'jobs' => $this->rows('SELECT * FROM job_state ORDER BY task'),
            'configured_gateways' => $this->rows("SELECT 1") === [] ? [] : $this->gatewayNames(),
            'env' => $this->env,
        ], 'admin/layout');
    }

    public function settingsSave(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $keys = [
            'features.hotspot_calendar_expiry',
            'features.persistent_account',
            'features.subscription_paid_until',
            'features.pppoe_qris_unique',
            'features.wifi_captive_portal',
            'pppoe_qris.auto_create',
            'pppoe_qris.providers',
            'pppoe_qris.guarantee_days',
            'wifi.ssid',
            'wifi.allowed_ssids',
            'wifi.brand_name',
            'wifi.logo_url',
            'wifi.terms_url',
            'wifi.session_token_ttl',
            'wifi.password_length',
            'wifi.calendar_days_default',
            'wifi.max_pending_orders_per_mac',
            'wifi.rate_limit_create_per_ip',
            'wifi.rate_limit_create_per_mac',
            'cron.grace_isolir_days',
            'payment.default_gateway',
            'payment.fallback_chain',
            'payment.order_ttl',
        ];
        $values = [];
        foreach ($keys as $key) {
            $value = $this->postString($request, $key);
            if (str_starts_with($key, 'features.')) {
                $value = $value !== '' ? '1' : '0';
            }
            $values[$key] = $value;
        }

        try {
            $this->settings->setMany($values);
            $this->audit->record('admin', $this->adminActor(), 'settings.updated', 'settings', null, ['keys' => array_keys($values)]);
            $this->session->flash('success', 'Pengaturan disimpan.');
        } catch (Throwable $e) {
            $this->session->flash('error', 'Gagal menyimpan pengaturan: ' . $e->getMessage());
        }

        return $this->redirect('/admin/settings');
    }

    public function testGateway(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $names = $this->gatewayNames();
        $selected = $this->postString($request, 'gateway');

        return JsonResponse::ok([
            'configured' => $names,
            'selected' => $selected,
            'selected_configured' => $selected !== '' && in_array($selected, $names, true),
            'checked_at' => Time::nowIso(),
        ]);
    }

    public function audit(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        return $this->render('admin/audit.php', [
            'pageTitle' => 'Audit Trail',
            'entries' => $this->audit->recent(300),
        ], 'admin/layout');
    }

    /** @return array<string,mixed> */
    private function reconciliationData(ServerRequestInterface $request): array
    {
        $days = max(1, min(90, (int) $this->queryString($request, 'days', '14')));

        return [
            'days' => $days,
            'pending' => $this->reconciliation->pending($days),
            'unmatched' => $this->reconciliation->unmatchedWebhooks(50),
            'qris_accounts' => $this->reconciliation->qrisAccounts(100),
            'stats' => $this->reconciliation->stats($days),
        ];
    }

    /** @return list<string> */
    private function gatewayNames(): array
    {
        $names = [];
        foreach (\Billas\Domain\PaymentGateway\GatewayFactory::PROVIDERS as $name => $_class) {
            if ($this->isGatewayConfigured($name)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    private function isGatewayConfigured(string $name): bool
    {
        try {
            $provider = (new \Billas\Domain\PaymentGateway\GatewayFactory($this->env, new \Monolog\Logger('probe')))->make($name);

            return $provider->isConfigured();
        } catch (Throwable) {
            return false;
        }
    }

    /** @return list<array<string,mixed>> */
    private function rows(string $sql, array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }
}
