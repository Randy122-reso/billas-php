<?php

declare(strict_types=1);

namespace Billas\Middleware;

use Billas\Support\Csrf;
use Billas\Support\Http;
use Billas\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Double-submit form protection. Exemptions are intentionally narrow: gateway
 * webhooks, cron callbacks, and the HMAC-protected WiFi API.
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    private const EXEMPT_PREFIXES = [
        '/internal/',
        '/api/webhook/',
        '/api/wifi/',
    ];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = strtoupper($request->getMethod());
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $handler->handle($request);
        }

        $path = Http::path($request);
        foreach (self::EXEMPT_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return $handler->handle($request);
            }
        }

        $token = $request->getHeaderLine('X-CSRF-Token');
        if ($token === '') {
            $body = $request->getParsedBody();
            if (is_array($body)) {
                $token = (string) ($body['_csrf'] ?? $body['csrf_token'] ?? '');
            }
        }
        if ($token === '') {
            $token = (string) ($_POST['_csrf'] ?? '');
        }

        if (!Csrf::validate($token)) {
            $message = 'Sesi formulir kedaluwarsa. Muat ulang halaman lalu coba lagi.';
            if (str_starts_with($path, '/api/') || Http::wantsJson($request)) {
                return JsonResponse::error($message, 419);
            }

            return JsonResponse::html(
                '<!doctype html><meta charset="utf-8"><title>419</title>'
                . '<body style="font-family:system-ui;padding:3rem"><h1>419</h1><p>'
                . e($message) . '</p><p><a href="javascript:history.back()">Kembali</a></p></body>',
                419
            );
        }

        return $handler->handle($request);
    }
}
