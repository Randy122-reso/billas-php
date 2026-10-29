<?php

declare(strict_types=1);

namespace Billas\Controller;

use Billas\Domain\WebhookSimulatorService;
use Billas\Support\Env;
use Billas\Support\Http;
use Billas\Support\JsonResponse;
use Billas\Support\Session;
use Billas\Support\Settings;
use Billas\Support\View;
use InvalidArgumentException;
use LogicException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Throwable;

/**
 * Fitur F (v4.2): admin-only webhook simulator UI and dispatch endpoint.
 * AuthMiddleware + CsrfMiddleware + RateLimitMiddleware + SimulatorFlagMiddleware
 * are applied by the route table; this controller only validates input.
 */
final class WebhookSimulatorController extends AbstractController
{
    public function __construct(
        View $view,
        Session $session,
        Settings $settings,
        private readonly WebhookSimulatorService $simulator,
        private readonly Env $env,
    ) {
        parent::__construct($view, $session, $settings);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $options = $this->simulator->options();
        $provider = $this->queryString($request, 'provider');
        if ($provider === '') {
            $configured = $options['status']['configured_providers'];
            $provider = $configured[0] ?? 'tripay';
        }
        $runId = (int) $this->queryString($request, 'run', '0');

        return $this->render('admin/simulator.php', [
            'pageTitle' => 'Simulator Webhook',
            'options' => $options,
            'runs' => $this->simulator->recentRuns(20),
            'last_run' => $runId > 0 ? $this->simulator->findRun($runId) : null,
            'prefill' => [
                'target_type' => $this->queryString($request, 'target_type', 'order'),
                'target_id' => $this->queryString($request, 'target_id'),
                'provider' => $provider,
                'event' => strtoupper($this->queryString($request, 'event', 'PAID')),
                'amount' => $this->queryString($request, 'amount'),
                'mode' => $this->queryString($request, 'mode', $this->simulator->mode()),
            ],
            'env' => $this->env,
        ], 'admin/layout');
    }

    public function send(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $targetType = strtolower($this->postString($request, 'target_type'));
        $targetId = $this->postString($request, 'target_id');
        $provider = strtolower($this->postString($request, 'provider'));
        $event = strtoupper($this->postString($request, 'event'));
        $mode = strtolower($this->postString($request, 'mode', $this->simulator->mode()));
        $amountRaw = preg_replace('/\D+/', '', $this->postString($request, 'amount')) ?? '';
        $amount = $amountRaw === '' ? null : (int) $amountRaw;

        $payload = [
            'target_type' => $targetType,
            'target_id' => $targetId,
            'provider' => $provider,
            'event' => $event,
            'mode' => $mode,
            'amount' => $amount,
        ];

        if ($targetId === '') {
            return $this->failure($request, 'Target ID wajib diisi.', 422, $payload);
        }
        if ($mode === 'sign' && $provider === 'qris_static') {
            return $this->failure($request, 'QRIS statis tidak memiliki signature; pilih mode bypass.', 422, $payload);
        }

        try {
            $result = $this->simulator->simulate(
                $targetType,
                $targetId,
                $provider,
                $event,
                $amount,
                $mode,
                $this->session->adminId()
            );

            if ($this->wantsJson($request)) {
                return JsonResponse::ok($result);
            }
            $this->session->flash('success', 'Simulasi ' . $provider . ' ' . $event . ': ' . ($result['message'] ?? $result['status'] ?? 'selesai'));
            $runId = (int) ($result['run_id'] ?? 0);

            return $this->redirect('/admin/simulator' . ($runId > 0 ? '?run=' . $runId : ''));
        } catch (Throwable $e) {
            $status = $this->statusFor($e);

            return $this->failure($request, $e->getMessage(), $status, $payload);
        }
    }

    public function runs(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        return JsonResponse::ok(['runs' => $this->simulator->recentRuns(20)]);
    }

    /** @param array<string,mixed> $prefill */
    private function failure(ServerRequestInterface $request, string $message, int $status, array $prefill): ResponseInterface
    {
        if ($this->wantsJson($request)) {
            return JsonResponse::error($message, $status, ['prefill' => $prefill]);
        }

        $this->session->flash('error', 'Simulasi gagal: ' . $message);
        $query = http_build_query(array_filter([
            'target_type' => $prefill['target_type'] ?? null,
            'target_id' => $prefill['target_id'] ?? null,
            'provider' => $prefill['provider'] ?? null,
            'event' => $prefill['event'] ?? null,
            'mode' => $prefill['mode'] ?? null,
            'amount' => $prefill['amount'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== ''));

        return $this->redirect('/admin/simulator' . ($query !== '' ? '?' . $query : ''));
    }

    private function wantsJson(ServerRequestInterface $request): bool
    {
        return Http::wantsJson($request) || str_contains($request->getHeaderLine('Accept'), 'application/json');
    }

    private function statusFor(Throwable $e): int
    {
        if ($e instanceof InvalidArgumentException || $e instanceof LogicException) {
            return 422;
        }
        if ($e instanceof RuntimeException) {
            $message = strtolower($e->getMessage());

            return str_contains($message, 'dimatikan') || str_contains($message, 'production') ? 403 : 422;
        }

        return 500;
    }
}
