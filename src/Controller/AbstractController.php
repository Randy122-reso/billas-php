<?php

declare(strict_types=1);

namespace Billas\Controller;

use Billas\Support\JsonResponse;
use Billas\Support\Session;
use Billas\Support\Settings;
use Billas\Support\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

abstract class AbstractController
{
    public function __construct(
        protected readonly View $view,
        protected readonly Session $session,
        protected readonly Settings $settings,
    ) {
    }

    /** @param array<string,mixed> $data */
    protected function render(string $template, array $data = [], ?string $layout = 'layout'): ResponseInterface
    {
        return JsonResponse::html($this->view->render($template, $data, $layout));
    }

    protected function redirect(string $url, int $status = 302): ResponseInterface
    {
        return JsonResponse::redirect($url, $status);
    }

    /** @return array<string,mixed> */
    protected function input(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();
        if (is_array($parsed)) {
            return $parsed;
        }

        return $_POST;
    }

    /** @return array<string,mixed> */
    protected function query(ServerRequestInterface $request): array
    {
        return $request->getQueryParams();
    }

    protected function postString(ServerRequestInterface $request, string $key, string $default = ''): string
    {
        $input = $this->input($request);
        $value = $input[$key] ?? $default;

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    protected function postInt(ServerRequestInterface $request, string $key, int $default = 0): int
    {
        $input = $this->input($request);
        $value = $input[$key] ?? $default;

        return is_numeric($value) ? (int) $value : $default;
    }

    protected function queryString(ServerRequestInterface $request, string $key, string $default = ''): string
    {
        $value = $request->getQueryParams()[$key] ?? $default;

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    /** @return list<string> */
    protected function postList(ServerRequestInterface $request, string $key): array
    {
        $value = $this->input($request)[$key] ?? [];

        return is_array($value) ? array_values(array_map('strval', $value)) : [];
    }

    protected function adminActor(): string
    {
        return (string) ($_SESSION['admin_username'] ?? 'admin');
    }
}
