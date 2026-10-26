<?php

declare(strict_types=1);

namespace Billas\Controller;

use Billas\Domain\CronService;
use Billas\Support\Env;
use Billas\Support\Http;
use Billas\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** RouterOS Scheduler entry point. Authentication is handled by middleware. */
final class InternalController
{
    public function __construct(
        private readonly CronService $cron,
        private readonly Env $env,
    ) {
    }

    public function cron(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        if (!$this->env->bool('CRON_ENABLED', true)) {
            return JsonResponse::error('Cron dinonaktifkan.', 503);
        }

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            $decoded = json_decode(Http::rawBody($request), true);
            $body = is_array($decoded) ? $decoded : [];
        }

        $tasks = $body['tasks'] ?? [];
        if (!is_array($tasks)) {
            return JsonResponse::error('Field tasks harus berupa array.', 422);
        }
        $tasks = array_values(array_filter(array_map('strval', $tasks), static fn (string $v): bool => $v !== ''));
        $force = filter_var($body['force'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return JsonResponse::ok($this->cron->tick($tasks, $force));
    }
}
