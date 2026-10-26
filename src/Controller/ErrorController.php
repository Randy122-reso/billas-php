<?php

declare(strict_types=1);

namespace Billas\Controller;

use Billas\Support\Env;
use Billas\Support\JsonResponse;
use Billas\Support\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * Renders the generic error page. Kept separate so the error middleware can use
 * it without resolving a full controller graph.
 */
final class ErrorController
{
    public function __construct(
        private readonly View $view,
        private readonly Env $env,
    ) {
    }

    public function render(ServerRequestInterface $request, Throwable $error, int $status = 500): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $isApi = str_starts_with($path, '/api/')
            || str_starts_with($path, '/internal/')
            || str_contains($request->getHeaderLine('Accept'), 'application/json');

        $message = $status >= 500 && !$this->env->bool('APP_DEBUG')
            ? 'Terjadi kesalahan pada server. Silakan coba lagi.'
            : $error->getMessage();

        if ($isApi) {
            return JsonResponse::error($message, $status, $this->env->bool('APP_DEBUG') ? [
                'exception' => $error::class,
                'file' => $error->getFile(),
                'line' => $error->getLine(),
            ] : []);
        }

        $html = $this->view->render('error.php', [
            'pageTitle' => 'Terjadi Kesalahan',
            'status' => $status,
            'message' => $message,
            'exception' => $this->env->bool('APP_DEBUG') ? $error::class : null,
            'trace' => $this->env->bool('APP_DEBUG') ? $error->getTraceAsString() : null,
        ], 'layout');

        return JsonResponse::html($html, $status);
    }
}
