<?php

declare(strict_types=1);

namespace Billas\Middleware;

use Billas\Domain\CaptivePortalService;
use Billas\Support\JsonResponse;
use Billas\Support\Settings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Validates the signed captive-portal session token. The token binds router,
 * SSID, client IP/MAC, and creation time, and must still be pending in the DB.
 */
final class CaptiveSessionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly CaptivePortalService $captive,
        private readonly Settings $settings,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->settings->feature('wifi_captive_portal')) {
            return JsonResponse::error('Captive portal sedang dinonaktifkan.', 403);
        }

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            $decoded = json_decode((string) $request->getBody(), true);
            $body = is_array($decoded) ? $decoded : [];
        }
        $token = (string) ($body['session_token'] ?? '');
        $session = $this->captive->validateSessionToken($token);
        if ($session === null) {
            return JsonResponse::error('Sesi captive portal tidak valid atau sudah kedaluwarsa. Muat ulang halaman.', 401);
        }

        return $handler->handle($request->withAttribute('captive_session', $session)->withAttribute('captive_token', $token));
    }
}
