<?php

declare(strict_types=1);

namespace Billas\Middleware;

use Billas\Controller\ErrorController;
use Billas\Support\Env;
use Billas\Support\Http;
use Billas\Support\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpException;
use Throwable;

final class ErrorHandlerMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly View $view,
        private readonly Env $env,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (Throwable $error) {
            $status = $error instanceof HttpException ? $error->getCode() : 500;
            if ($status < 400 || $status > 599) {
                $status = 500;
            }

            $context = [
                'method' => $request->getMethod(),
                'path' => Http::path($request),
                'ip' => Http::ip($request),
                'exception' => $error::class,
            ];
            if ($status >= 500) {
                $this->logger->error('Unhandled request error: ' . $error->getMessage(), $context + [
                    'trace' => $error->getTraceAsString(),
                ]);
            } else {
                $this->logger->warning('Request error: ' . $error->getMessage(), $context);
            }

            return (new ErrorController($this->view, $this->env))->render($request, $error, $status);
        }
    }
}
