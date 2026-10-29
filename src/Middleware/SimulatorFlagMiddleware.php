<?php

declare(strict_types=1);

namespace Billas\Middleware;

use Billas\Support\Env;
use Billas\Support\Http;
use Billas\Support\JsonResponse;
use Billas\Support\Settings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Fitur F (v4.2): block /admin/simulator* unless the feature flag is on and,
 * in production, the explicit allow flag is also on. This complements the
 * boot-time Env::validate() guard so a runtime settings change cannot silently
 * expose the simulator.
 */
final class SimulatorFlagMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Env $env,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $enabled = $this->settings->feature('webhook_simulator');
        $productionBlocked = $this->env->isProduction()
            && !$this->settings->bool('webhook_simulator.allow_in_production', false);

        if (!$enabled || $productionBlocked) {
            $message = $productionBlocked
                ? 'Simulator diblokir di production tanpa WEBHOOK_SIMULATOR_ALLOW_IN_PRODUCTION=true.'
                : 'Simulator dimatikan (FEATURE_WEBHOOK_SIMULATOR=false).';

            if (Http::wantsJson($request)) {
                return JsonResponse::error($message, 403);
            }

            return JsonResponse::html(
                '<!doctype html><meta charset="utf-8"><title>403</title>'
                . '<body style="font-family:system-ui;padding:3rem"><h1>403</h1><p>'
                . e($message) . '</p><p><a href="' . e(billas_url('/admin/dashboard')) . '">Kembali ke dashboard</a></p></body>',
                403
            );
        }

        return $handler->handle($request);
    }
}
