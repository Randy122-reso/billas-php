<?php

declare(strict_types=1);

namespace Billas\Controller;

use Billas\Domain\PaymentGateway\GatewayFactory;
use Billas\Support\Env;
use Billas\Support\JsonResponse;
use Billas\Support\Settings;
use Billas\Support\Time;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

final class HealthController
{
    public function __construct(
        private readonly PDO $db,
        private readonly Env $env,
        private readonly Settings $settings,
        private readonly GatewayFactory $gateways,
    ) {
    }

    public function health(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        return JsonResponse::ok([
            'service' => 'billas',
            'version' => $this->env->string('APP_VERSION', '4.0.0'),
            'time' => Time::nowIso(),
        ]);
    }

    public function ready(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $checks = [];
        $ok = true;

        try {
            $this->db->query('SELECT 1')->fetchColumn();
            $checks['database'] = ['ok' => true];
            $missing = [];
            foreach (['packages', 'customers', 'orders', 'invoices', 'vouchers', 'captive_portal_sessions', 'job_state'] as $table) {
                $stmt = $this->db->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
                $stmt->execute([$table]);
                if (!$stmt->fetchColumn()) {
                    $missing[] = $table;
                }
            }
            $checks['schema'] = ['ok' => $missing === [], 'missing' => $missing];
            $ok = $ok && $missing === [];
        } catch (Throwable $e) {
            $checks['database'] = ['ok' => false, 'error' => $e->getMessage()];
            $ok = false;
        }

        $configured = $this->gateways->configuredNames();
        $checks['gateways'] = [
            'ok' => $configured !== [],
            'configured' => $configured,
            'default' => $this->settings->string('payment.default_gateway', 'qris_static'),
        ];
        $ok = $ok && $configured !== [];

        $checks['features'] = $this->settings->features();
        $checks['warnings'] = $this->env->warnings();

        return JsonResponse::json([
            'ok' => $ok,
            'ready' => $ok,
            'checks' => $checks,
            'time' => Time::nowIso(),
        ], $ok ? 200 : 503);
    }
}
