<?php

declare(strict_types=1);

namespace Billas\Middleware;

use Billas\Support\Http;
use Billas\Support\Session;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Starts the SQLite-backed session for browser-facing routes only. */
final class StartSessionMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Session $session)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = Http::path($request);
        $skip = $path === '/health'
            || $path === '/ready'
            || str_starts_with($path, '/internal/')
            || str_starts_with($path, '/api/webhook/')
            || str_starts_with($path, '/api/wifi/')
            || $path === '/wifi'
            || str_starts_with($path, '/wifi/');
        if ($skip) {
            return $handler->handle($request);
        }

        $this->session->start();
        try {
            return $handler->handle($request);
        } finally {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
        }
    }
}
