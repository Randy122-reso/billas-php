<?php

declare(strict_types=1);

namespace Billas\Domain;

use Billas\Domain\PaymentGateway\GatewayFactory;
use Billas\Support\Env;
use Billas\Support\Settings;
use Billas\Support\Time;
use LogicException;
use PDO;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Fitur F (v4.2): admin-only webhook simulator.
 *
 * Builds a provider-shaped synthetic payload, dispatches it through the real
 * WebhookService pipeline, and records a masked observability row. It never
 * bypasses the feature flag, the admin session guard, or the
 * X-Billas-Simulated header requirement.
 */
final class WebhookSimulatorService
{
    private const TARGET_TYPES = ['order', 'invoice', 'persistent_account'];
    private const EVENTS = ['PAID', 'EXPIRED', 'FAILED'];
    private const MODES = ['sign', 'bypass'];
    private const SIGNATURE_HEADERS = [
        'x-callback-signature',
        'x-signature',
        'x-signature-key',
        'authorization',
        'x-callback-token',
    ];

    public function __construct(
        private readonly PDO $db,
        private readonly WebhookService $webhooks,
        private readonly GatewayFactory $gateways,
        private readonly OrderService $orders,
        private readonly InvoiceService $invoices,
        private readonly CustomerPaymentAccountService $accounts,
        private readonly AuditService $audit,
        private readonly Settings $settings,
        private readonly Env $env,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function enabled(): bool
    {
        return $this->settings->feature('webhook_simulator');
    }

    public function mode(): string
    {
        $mode = strtolower(trim($this->settings->string('webhook_simulator.mode', 'sign')));

        return in_array($mode, self::MODES, true) ? $mode : 'sign';
    }

    public function allowInProduction(): bool
    {
        return $this->settings->bool('webhook_simulator.allow_in_production', false);
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        return [
            'enabled' => $this->enabled(),
            'mode' => $this->mode(),
            'allow_in_production' => $this->allowInProduction(),
            'production' => $this->env->isProduction(),
            'configured_providers' => $this->env->configuredPaymentProviders(),
            'signing_providers' => $this->env->configuredWebhookSigningProviders(),
        ];
    }

    /** @return array<string,mixed> */
    public function options(): array
    {
        $providers = [];
        foreach (array_keys(GatewayFactory::PROVIDERS) as $name) {
            $providers[] = [
                'name' => $name,
                'configured' => $this->gateways->configured($name),
                'supports_signature' => $name !== 'qris_static',
            ];
        }

        return [
            'orders' => $this->db->query(
                'SELECT o.id, o.merchant_ref, o.amount, o.gateway, o.source, o.status, o.created_at,
                        p.name AS package_name
                 FROM orders o JOIN packages p ON p.id = o.package_id
                 ORDER BY o.created_at DESC LIMIT 10'
            )->fetchAll(),
            'invoices' => $this->db->query(
                'SELECT i.id, i.invoice_no, i.amount, i.status, i.period, i.period_end,
                        c.name AS customer_name, c.code AS customer_code
                 FROM invoices i JOIN customers c ON c.id = i.customer_id
                 ORDER BY i.id DESC LIMIT 10'
            )->fetchAll(),
            'accounts' => $this->accounts->recent(10),
            'providers' => $providers,
            'events' => self::EVENTS,
            'modes' => self::MODES,
            'status' => $this->status(),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function recentRuns(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $rows = $this->db->query("SELECT * FROM simulator_runs ORDER BY id DESC LIMIT {$limit}")->fetchAll();

        return array_map([$this, 'hydrateRun'], $rows);
    }

    /** @return array<string,mixed>|null */
    public function findRun(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM simulator_runs WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ? $this->hydrateRun($row) : null;
    }

    /**
     * @return array<string,mixed>
     */
    public function build(
        string $targetType,
        string $targetId,
        string $provider,
        string $event,
        ?int $amountOverride = null
    ): array {
        $targetType = strtolower(trim($targetType));
        $event = strtoupper(trim($event));
        $provider = strtolower(trim($provider));

        if (!in_array($targetType, self::TARGET_TYPES, true)) {
            throw new \InvalidArgumentException('target_type tidak valid: ' . $targetType);
        }
        if (!in_array($event, self::EVENTS, true)) {
            throw new \InvalidArgumentException('Event tidak didukung: ' . $event);
        }
        if (!isset(GatewayFactory::PROVIDERS[$provider])) {
            throw new \InvalidArgumentException('Provider tidak dikenal: ' . $provider);
        }

        $target = $this->resolveTarget($targetType, $targetId, $provider, $amountOverride);
        if ($target === null) {
            throw new RuntimeException('Target ' . $targetType . ':' . $targetId . ' tidak ditemukan.');
        }

        $gateway = $this->gateways->make($provider);
        if (!method_exists($gateway, 'buildSimulatedWebhook')) {
            throw new LogicException('Provider ' . $provider . ' belum mendukung simulator.');
        }
        $built = $gateway->buildSimulatedWebhook($target, $event, $amountOverride);
        if (!isset($built['body']) || !is_array($built['body'])) {
            throw new LogicException('Provider ' . $provider . ' tidak menghasilkan payload simulator.');
        }

        return [
            'provider' => $provider,
            'event' => $event,
            'target' => $target,
            'target_type' => $targetType,
            'target_id' => (string) $targetId,
            'body' => $built['body'],
            'headers' => is_array($built['headers'] ?? null) ? $built['headers'] : [],
            'body_format' => ($built['body_format'] ?? 'json') === 'form' ? 'form' : 'json',
        ];
    }

    /**
     * @param array<string,mixed> $built
     * @return array<string,mixed>
     */
    public function dispatch(array $built, string $mode, ?int $adminId = null): array
    {
        $mode = strtolower(trim($mode));
        if (!in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException('Mode simulator tidak valid: ' . $mode);
        }
        if (!$this->enabled()) {
            throw new RuntimeException('Simulator dimatikan.');
        }

        $provider = (string) ($built['provider'] ?? '');
        if ($mode === 'sign') {
            if ($provider === 'qris_static') {
                throw new LogicException('QRIS statis tidak memiliki signature webhook; gunakan mode bypass.');
            }
            if (!$this->gateways->configured($provider)) {
                throw new RuntimeException('Gateway belum dikonfigurasi: ' . $provider);
            }
        }

        $rawBody = $this->serializeBody($built);
        $headers = is_array($built['headers'] ?? null) ? $built['headers'] : [];
        $headers['X-Billas-Simulated'] = '1';

        try {
            $result = $this->webhooks->handle($provider, $headers, $rawBody, true, $mode === 'bypass');
            $runId = $this->recordRun($built, $mode, $rawBody, $headers, 200, $result, $adminId);
            $this->auditDispatch($adminId, $runId, $built, $mode, 'ok');

            return $result + ['mode' => $mode, 'run_id' => $runId];
        } catch (Throwable $e) {
            $status = $this->httpStatusForError($e);
            $result = ['status' => 'error', 'message' => $e->getMessage()];
            $runId = $this->recordRun($built, $mode, $rawBody, $headers, $status, $result, $adminId);
            $this->auditDispatch($adminId, $runId, $built, $mode, 'error', $e->getMessage());
            throw $e;
        }
    }

    /**
     * One-call convenience wrapper used by the controller and CLI. Build
     * failures are still recorded in simulator_runs for auditability.
     *
     * @return array<string,mixed>
     */
    public function simulate(
        string $targetType,
        string $targetId,
        string $provider,
        string $event,
        ?int $amountOverride,
        string $mode,
        ?int $adminId = null
    ): array {
        try {
            $built = $this->build($targetType, $targetId, $provider, $event, $amountOverride);
        } catch (Throwable $e) {
            $runId = $this->recordFailedBuild($targetType, $targetId, $provider, $event, $mode, $e, $adminId);
            $this->auditDispatch($adminId, $runId, [
                'provider' => strtolower(trim($provider)),
                'event' => strtoupper(trim($event)),
                'target' => ['id' => $targetId],
                'target_type' => strtolower(trim($targetType)),
            ], strtolower(trim($mode)), 'error', $e->getMessage());
            throw $e;
        }

        return $this->dispatch($built, $mode, $adminId);
    }

    /**
     * Redact signature values before they touch the database. Returns a JSON
     * envelope with masked headers and a masked body; the value is safe to show
     * in the admin UI.
     *
     * @param array<string,string> $headers
     */
    public function maskSignature(string $rawBody, array $headers): string
    {
        $maskedBody = $rawBody;
        $decoded = json_decode($rawBody, true);
        if (is_array($decoded)) {
            foreach (['signature_key', 'signature', 'token', 'callback_token'] as $key) {
                if (array_key_exists($key, $decoded)) {
                    $decoded[$key] = '***MASKED***';
                }
            }
            $maskedBody = (string) json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } else {
            $parsed = [];
            parse_str($rawBody, $parsed);
            if ($parsed !== []) {
                foreach (['signature', 'signature_key', 'token'] as $key) {
                    if (array_key_exists($key, $parsed)) {
                        $parsed[$key] = '***MASKED***';
                    }
                }
                $maskedBody = http_build_query($parsed);
            }
        }

        $maskedHeaders = [];
        foreach ($headers as $name => $value) {
            $lower = strtolower((string) $name);
            if (in_array($lower, self::SIGNATURE_HEADERS, true)) {
                if ((string) $value !== '') {
                    $maskedBody = str_replace((string) $value, '***MASKED***', $maskedBody);
                }
                $maskedHeaders[$name] = '***MASKED***';
            } else {
                $maskedHeaders[$name] = (string) $value;
            }
        }

        return (string) json_encode([
            'headers' => $maskedHeaders,
            'body' => $maskedBody,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @param array<string,mixed> $built */
    private function serializeBody(array $built): string
    {
        $body = is_array($built['body'] ?? null) ? $built['body'] : [];
        if (($built['body_format'] ?? 'json') === 'form') {
            return http_build_query($body);
        }

        return (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function resolveTarget(
        string $targetType,
        string $targetId,
        string $provider,
        ?int $amountOverride
    ): ?array {
        if ($targetType === 'order') {
            return $this->orders->find($targetId);
        }

        if ($targetType === 'invoice') {
            $invoice = $this->invoices->find((int) $targetId);
            if ($invoice === null) {
                return null;
            }
            $transaction = $this->pendingInvoiceTransaction($invoice, $provider);
            $invoice['merchant_ref'] = (string) $transaction['merchant_ref'];
            $invoice['amount'] = (int) $transaction['amount'];
            $invoice['gateway'] = $provider;
            $invoice['transaction_id'] = (int) $transaction['id'];

            return $invoice;
        }

        $account = $this->accounts->find((int) $targetId);
        if ($account === null) {
            return null;
        }
        $account['merchant_ref'] = (string) ($account['qris_unique_ref'] ?: $account['external_ref']);
        $account['amount'] = $amountOverride ?? $this->persistentAmount($account);

        return $account;
    }

    /**
     * Invoice webhooks match payment_transactions.merchant_ref. Reuse the
     * newest pending transaction or create a synthetic one so the simulator can
     * exercise the same pipeline as a real invoice payment.
     *
     * @param array<string,mixed> $invoice
     * @return array<string,mixed>
     */
    private function pendingInvoiceTransaction(array $invoice, string $provider): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM payment_transactions
             WHERE invoice_id = ? AND status = 'pending'
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([(int) $invoice['id']]);
        $existing = $stmt->fetch();
        if ($existing) {
            return $existing;
        }

        $merchantRef = $this->uniqueMerchantRef();
        $now = Time::now();
        $this->db->prepare(
            'INSERT INTO payment_transactions
             (invoice_id, gateway, merchant_ref, gateway_reference, amount, status, qr_string, payload,
              expires_at, created_at, updated_at)
             VALUES (?, ?, ?, NULL, ?, "pending", NULL, ?, ?, ?, ?)'
        )->execute([
            (int) $invoice['id'],
            $provider,
            $merchantRef,
            (int) $invoice['amount'],
            json_encode(['simulated' => true, 'invoice_no' => $invoice['invoice_no'] ?? null], JSON_UNESCAPED_SLASHES),
            $now + 900,
            $now,
            $now,
        ]);

        $stmt = $this->db->prepare('SELECT * FROM payment_transactions WHERE id = ?');
        $stmt->execute([(int) $this->db->lastInsertId()]);

        return $stmt->fetch() ?: throw new RuntimeException('Gagal membuat transaksi simulasi invoice.');
    }

    /** @param array<string,mixed> $account */
    private function persistentAmount(array $account): int
    {
        $open = $this->invoices->oldestOpen((int) $account['customer_id']);
        if ($open !== null) {
            return (int) $open['amount'];
        }

        $stmt = $this->db->prepare(
            'SELECT p.price FROM customers c JOIN packages p ON p.id = c.package_id WHERE c.id = ?'
        );
        $stmt->execute([(int) $account['customer_id']]);
        $price = $stmt->fetchColumn();

        return $price === false ? 0 : (int) $price;
    }

    private function uniqueMerchantRef(): string
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $ref = 'SIM-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
            $stmt = $this->db->prepare(
                'SELECT 1 FROM orders WHERE merchant_ref = ?
                 UNION ALL SELECT 1 FROM payment_transactions WHERE merchant_ref = ? LIMIT 1'
            );
            $stmt->execute([$ref, $ref]);
            if (!$stmt->fetchColumn()) {
                return $ref;
            }
        }

        throw new RuntimeException('Tidak dapat membuat merchant reference simulasi.');
    }

    /**
     * @param array<string,mixed> $built
     * @param array<string,string> $headers
     * @param array<string,mixed> $result
     */
    private function recordRun(
        array $built,
        string $mode,
        string $rawBody,
        array $headers,
        int $status,
        array $result,
        ?int $adminId = null
    ): int {
        return $this->insertRun(
            (string) ($built['target_type'] ?? ''),
            (string) ($built['target_id'] ?? ($built['target']['id'] ?? '')),
            (string) ($built['provider'] ?? ''),
            (string) ($built['event'] ?? ''),
            $mode,
            $this->maskSignature($rawBody, $headers),
            $status,
            (string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $adminId
        );
    }

    private function recordFailedBuild(
        string $targetType,
        string $targetId,
        string $provider,
        string $event,
        string $mode,
        Throwable $e,
        ?int $adminId = null
    ): int {
        return $this->insertRun(
            strtolower(trim($targetType)),
            $targetId,
            strtolower(trim($provider)),
            strtoupper(trim($event)),
            in_array(strtolower(trim($mode)), self::MODES, true) ? strtolower(trim($mode)) : 'sign',
            null,
            $this->httpStatusForError($e),
            (string) json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $adminId
        );
    }

    private function insertRun(
        string $targetType,
        string $targetId,
        string $provider,
        string $event,
        string $mode,
        ?string $requestBody,
        int $status,
        string $responseBody,
        ?int $createdBy = null
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO simulator_runs
             (target_type, target_id, provider, event, mode, request_body,
              response_status, response_body, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $targetType,
            $targetId,
            $provider,
            $event,
            $mode,
            $requestBody,
            $status,
            $responseBody,
            $createdBy,
            Time::now(),
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * @param array<string,mixed> $built
     */
    private function auditDispatch(
        ?int $adminId,
        int $runId,
        array $built,
        string $mode,
        string $status,
        ?string $error = null
    ): void {
        $meta = [
            'provider' => $built['provider'] ?? null,
            'event' => $built['event'] ?? null,
            'mode' => $mode,
            'status' => $status,
            'run_id' => $runId,
        ];
        if ($error !== null) {
            $meta['error'] = $error;
        }
        $this->audit->record(
            'admin',
            $adminId !== null ? (string) $adminId : 'cli',
            'simulator.dispatch',
            'simulator_run',
            (string) $runId,
            $meta
        );
    }

    /** @param array<string,mixed> $row */
    private function hydrateRun(array $row): array
    {
        $row['request'] = json_decode((string) ($row['request_body'] ?? ''), true);
        $row['response'] = json_decode((string) ($row['response_body'] ?? ''), true);

        return $row;
    }

    private function httpStatusForError(Throwable $e): int
    {
        if (str_contains(strtolower($e->getMessage()), 'signature')) {
            return 401;
        }
        if ($e instanceof \InvalidArgumentException || $e instanceof LogicException) {
            return 422;
        }

        return 422;
    }
}
