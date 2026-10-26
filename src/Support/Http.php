<?php

declare(strict_types=1);

namespace Billas\Support;

use Psr\Http\Message\ServerRequestInterface;

final class Http
{
    public static function ip(?ServerRequestInterface $request = null): string
    {
        $candidates = [];
        if ($request !== null) {
            $candidates[] = $request->getHeaderLine('CF-Connecting-IP');
            $candidates[] = $request->getHeaderLine('X-Real-IP');
            $forwarded = $request->getHeaderLine('X-Forwarded-For');
            if ($forwarded !== '') {
                $candidates[] = trim(explode(',', $forwarded)[0]);
            }
            $candidates[] = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');
        } else {
            $candidates = [
                $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
                $_SERVER['HTTP_X_REAL_IP'] ?? '',
                explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))[0] ?? '',
                $_SERVER['REMOTE_ADDR'] ?? '',
            ];
        }

        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                return $candidate;
            }
        }

        return '0.0.0.0';
    }

    public static function clientMac(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = strtoupper(str_replace('-', ':', trim($value)));
        if (!preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/', $value)) {
            return null;
        }

        return $value;
    }

    public static function rawBody(?ServerRequestInterface $request = null): string
    {
        if ($request !== null) {
            $body = $request->getBody();
            if ($body->isSeekable()) {
                $body->rewind();
            }

            return (string) $body->getContents();
        }

        return (string) file_get_contents('php://input');
    }

    /** @return array<string,string> */
    public static function headers(?ServerRequestInterface $request = null): array
    {
        $result = [];
        if ($request !== null) {
            foreach ($request->getHeaders() as $name => $values) {
                $result[strtolower($name)] = implode(',', $values);
            }

            return $result;
        }

        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $result[$name] = (string) $value;
            }
        }

        return $result;
    }

    /** Request path with an optional BASE_URL subdirectory stripped. */
    public static function path(?ServerRequestInterface $request = null): string
    {
        $path = $request?->getUri()->getPath() ?? (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
        $base = (string) (parse_url((string) (billas_env('BASE_URL') ?: billas_env('APP_URL') ?: ''), PHP_URL_PATH) ?: '');
        $base = $base === '/' ? '' : rtrim($base, '/');
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }

        return $path === '' ? '/' : $path;
    }

    public static function isJsonRequest(?ServerRequestInterface $request = null): bool
    {
        $accept = $request?->getHeaderLine('Accept') ?? ($_SERVER['HTTP_ACCEPT'] ?? '');
        $contentType = $request?->getHeaderLine('Content-Type') ?? ($_SERVER['CONTENT_TYPE'] ?? '');
        $path = $request?->getUri()->getPath() ?? parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);

        return str_contains($accept, 'application/json')
            || str_contains($contentType, 'application/json')
            || str_starts_with((string) $path, '/api/');
    }

    public static function wantsJson(?ServerRequestInterface $request = null): bool
    {
        $accept = $request?->getHeaderLine('Accept') ?? ($_SERVER['HTTP_ACCEPT'] ?? '');
        $requestedWith = $request?->getHeaderLine('X-Requested-With') ?? ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');

        return str_contains($accept, 'application/json') || strtolower($requestedWith) === 'xmlhttprequest';
    }
}
