<?php

declare(strict_types=1);

namespace Billas\Support;

use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Response;

final class JsonResponse
{
    /** @param array<string,mixed> $data */
    public static function ok(array $data = [], int $status = 200, array $headers = []): ResponseInterface
    {
        return self::json(['ok' => true] + $data, $status, $headers);
    }

    /** @param array<string,mixed> $extra */
    public static function error(string $message, int $status = 400, array $extra = []): ResponseInterface
    {
        return self::json(['ok' => false, 'error' => $message] + $extra, $status);
    }

    /** @param array<string,mixed> $data */
    public static function json(array $data, int $status = 200, array $headers = []): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write((string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        $response = $response->withHeader('Content-Type', 'application/json; charset=utf-8');

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /** Render an HTML response. */
    public static function html(string $html, int $status = 200, array $headers = []): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write($html);
        $response = $response->withHeader('Content-Type', 'text/html; charset=utf-8');

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    public static function redirect(string $url, int $status = 302): ResponseInterface
    {
        return (new Response($status))->withHeader('Location', $url);
    }
}
