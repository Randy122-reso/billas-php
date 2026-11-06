<?php

declare(strict_types=1);

namespace Billas\Controller;

use Billas\Domain\AuditService;
use Billas\Domain\DebugApiService;
use Billas\Domain\PaymentGateway\GatewayFactory;
use Billas\Support\Env;
use Billas\Support\Http;
use Billas\Support\JsonResponse;
use Billas\Support\Session;
use Billas\Support\Settings;
use Billas\Support\View;
use InvalidArgumentException;
use LogicException;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Throwable;

/**
 * Fitur G (v4.3): admin-only Gateway API Tester controller.
 */
final class ApiTesterController extends AbstractController
{
    public function __construct(
        View $view,
        Session $session,
        Settings $settings,
        private readonly PDO $db,
        private readonly GatewayFactory $gateways,
        private readonly AuditService $audit,
        private readonly Env $env,
    ) {
        parent::__construct($view, $session, $settings);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $adminId = $this->session->adminId();
        $debugService = $this->createDebugService($adminId);
        $runs = $debugService->recentRuns(20);

        $allowedMethodsStr = (string) $this->settings->get('api_tester.allowed_methods', $this->env->string('API_TESTER_ALLOWED_METHODS', 'GET,POST,DELETE'));
        $allowedMethods = array_filter(array_map('trim', explode(',', strtoupper($allowedMethodsStr))));
        if ($allowedMethods === []) {
            $allowedMethods = ['GET', 'POST', 'DELETE'];
        }

        $providers = array_keys(GatewayFactory::PROVIDERS);
        $configuredProviders = $this->env->configuredPaymentProviders();

        $providerHints = [];
        foreach ($providers as $p) {
            try {
                $gw = $this->gateways->make($p);
                if (method_exists($gw, 'getApiTesterAllowedPaths')) {
                    $providerHints[$p] = $gw->getApiTesterAllowedPaths();
                } else {
                    $providerHints[$p] = [];
                }
            } catch (Throwable) {
                $providerHints[$p] = [];
            }
        }

        return $this->render('admin/api_tester.php', [
            'pageTitle' => 'Gateway API Tester',
            'providers' => $providers,
            'configuredProviders' => $configuredProviders,
            'allowedMethods' => $allowedMethods,
            'providerHints' => $providerHints,
            'runs' => $runs,
            'env' => $this->env,
            'settings' => $this->settings,
        ]);
    }

    public function send(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $adminId = $this->session->adminId();
        $debugService = $this->createDebugService($adminId);

        $parsed = $request->getParsedBody();
        if (!is_array($parsed)) {
            $raw = Http::rawBody($request);
            $parsed = json_decode($raw, true) ?? [];
        }

        $provider = strtolower(trim((string) ($parsed['provider'] ?? '')));
        $method = strtoupper(trim((string) ($parsed['method'] ?? 'GET')));
        $endpoint = trim((string) ($parsed['endpoint'] ?? ''));
        $body = isset($parsed['body']) && trim((string) $parsed['body']) !== '' ? (string) $parsed['body'] : null;

        try {
            if ($provider === '') {
                throw new InvalidArgumentException('Provider wajib dipilih.');
            }
            if ($endpoint === '') {
                throw new InvalidArgumentException('Endpoint wajib diisi.');
            }

            $result = $debugService->send($provider, $method, $endpoint, $body);

            if ($this->wantsJson($request)) {
                return JsonResponse::ok($result);
            }

            $this->session->flash('success', "Request {$provider} {$method} {$endpoint} selesai (Status {$result['status']}).");

            return $this->redirect('/admin/api-tester');
        } catch (Throwable $e) {
            $status = $this->statusFor($e);

            if ($this->wantsJson($request)) {
                return JsonResponse::error($e->getMessage(), $status);
            }

            $this->session->flash('error', 'API Tester gagal: ' . $e->getMessage());

            return $this->redirect('/admin/api-tester');
        }
    }

    public function runs(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $adminId = $this->session->adminId();
        $debugService = $this->createDebugService($adminId);

        return JsonResponse::ok(['runs' => $debugService->recentRuns(20)]);
    }

    private function createDebugService(?int $adminId): DebugApiService
    {
        $config = [
            'api_tester_allowed_methods' => $this->settings->get('api_tester.allowed_methods', $this->env->string('API_TESTER_ALLOWED_METHODS', 'GET,POST,DELETE')),
            'api_tester_timeout_seconds' => (int) $this->settings->get('api_tester.timeout_seconds', (string) $this->env->int('API_TESTER_TIMEOUT_SECONDS', 10)),
            'api_tester_max_body_bytes' => (int) $this->settings->get('api_tester.max_body_bytes', (string) $this->env->int('API_TESTER_MAX_BODY_BYTES', 65536)),
        ];

        return new DebugApiService(
            $this->db,
            $this->gateways,
            $this->audit,
            $config,
            $adminId
        );
    }

    private function wantsJson(ServerRequestInterface $request): bool
    {
        return Http::wantsJson($request) || str_contains($request->getHeaderLine('Accept'), 'application/json');
    }

    private function statusFor(Throwable $e): int
    {
        if ($e instanceof InvalidArgumentException || $e instanceof LogicException) {
            return 400;
        }

        return 500;
    }
}
