<?php

declare(strict_types=1);

namespace Billas\Middleware;

use Billas\Support\Env;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Env $env)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'same-origin')
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
            ->withHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->withHeader(
                'Content-Security-Policy',
                "default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; "
                . "script-src 'self' 'unsafe-inline'; connect-src 'self'; font-src 'self' data:; "
                . "object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'"
            );

        if (str_starts_with($this->env->string('APP_URL'), 'https://')) {
            $response = $response->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
