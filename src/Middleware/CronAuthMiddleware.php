<?php

declare(strict_types=1);

namespace Billas\Middleware;

use Billas\Support\Env;
use Billas\Support\Http;
use Billas\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Cron callbacks must present X-Cron-Token or a valid HMAC-SHA256 signature of
 * the raw request body using CRON_TOKEN. Both are compared in constant time.
 */
final class CronAuthMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Env $env)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $secret = $this->env->string('CRON_TOKEN');
        if ($secret === '') {
            return JsonResponse::error('CRON_TOKEN belum dikonfigurasi.', 503);
        }

        $provided = trim($request->getHeaderLine('X-Cron-Token'));
        $tokenOk = $provided !== '' && hash_equals($secret, $provided);

        $signature = trim($request->getHeaderLine('X-Cron-Signature'));
        if (str_starts_with($signature, 'sha256=')) {
            $signature = substr($signature, 7);
        }
        $expected = hash_hmac('sha256', Http::rawBody($request), $secret);
        $signatureOk = $signature !== '' && hash_equals($expected, $signature);

        // CRON_HMAC_ENABLED=true disables the bearer-token path and requires a
        // body signature, which also protects against body tampering.
        $hmacRequired = $this->env->bool('CRON_HMAC_ENABLED', false);
        $authorized = $hmacRequired ? $signatureOk : ($tokenOk || $signatureOk);
        if (!$authorized) {
            return JsonResponse::error('Token cron tidak valid.', 401);
        }

        return $handler->handle($request);
    }
}
