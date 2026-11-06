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
 * Fitur G (v4.3): block /admin/api-tester* unless the feature flag is on and,
 * in production, API_TESTER_ALLOW_PRODUCTION_REQUESTS is also true.
 */
final class ApiTesterFlagMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Env $env,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $enabled = $this->settings->feature('api_tester');
        $productionBlocked = $this->env->isProduction()
            && !$this->settings->bool('api_tester.allow_production_requests', false);

        if (!$enabled || $productionBlocked) {
            $message = $productionBlocked
                ? 'API Tester diblokir di production tanpa API_TESTER_ALLOW_PRODUCTION_REQUESTS=true.'
                : 'API Tester dimatikan (FEATURE_API_TESTER=false).';

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
