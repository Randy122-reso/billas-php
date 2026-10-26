<?php

declare(strict_types=1);

namespace Billas\Middleware;

use Billas\Support\JsonResponse;
use Billas\Support\Session;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Session $session,
        private readonly string $area = 'admin',
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $ok = $this->area === 'admin'
            ? $this->session->adminId() !== null
            : $this->session->customerId() !== null;

        if ($ok) {
            return $handler->handle($request);
        }

        $login = $this->area === 'admin' ? '/admin/login' : '/portal/login';
        $this->session->flash('warning', 'Silakan masuk terlebih dahulu.');

        return JsonResponse::redirect($login);
    }
}
