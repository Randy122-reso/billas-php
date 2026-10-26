<?php

declare(strict_types=1);

namespace Billas\Middleware;

use Billas\Domain\RateLimitService;
use Billas\Support\Http;
use Billas\Support\JsonResponse;
use Billas\Support\Settings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Fixed-window rate limits for sensitive endpoints. WiFi per-MAC limiting is
 * enforced here as well as the IP bucket; the MAC is read from the raw JSON or
 * form body before routing.
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly RateLimitService $limits,
        private readonly Settings $settings,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = Http::path($request);
        $method = strtoupper($request->getMethod());
        $ip = Http::ip($request);
        $rules = $this->rules($path, $method);

        foreach ($rules as [$bucket, $limit]) {
            if ($limit <= 0) {
                continue;
            }
            $result = $this->limits->hit($bucket, $ip, $limit);
            if (!$result['allowed']) {
                return $this->tooMany($request, $result['retry_after']);
            }
        }

        if ($method === 'POST' && $path === '/api/wifi/pay/create') {
            $mac = Http::clientMac($this->bodyValue($request, 'client_mac'));
            $macLimit = $this->settings->int('wifi.rate_limit_create_per_mac', 3);
            if ($mac !== null && $macLimit > 0) {
                $result = $this->limits->hit('wifi_pay_mac', $mac, $macLimit);
                if (!$result['allowed']) {
                    return $this->tooMany($request, $result['retry_after']);
                }
            }
        }

        return $handler->handle($request);
    }

    /** @return list<array{0:string,1:int}> */
    private function rules(string $path, string $method): array
    {
        if ($method === 'POST' && $path === '/api/pay/create') {
            return [['pay_create_ip', 10]];
        }
        if ($method === 'POST' && str_starts_with($path, '/api/webhook/')) {
            return [['webhook_ip', 120]];
        }
        if ($method === 'POST' && $path === '/internal/cron/run') {
            return [['cron_ip', 60]];
        }
        if ($method === 'POST' && in_array($path, ['/admin/login', '/portal/login'], true)) {
            return [['login_ip', 5]];
        }
        if ($method === 'POST' && $path === '/api/wifi/pay/create') {
            return [['wifi_pay_ip', max(1, $this->settings->int('wifi.rate_limit_create_per_ip', 5))]];
        }
        if ($method === 'GET' && $path === '/wifi') {
            return [['wifi_portal_ip', 30]];
        }
        if ($method === 'GET' && str_starts_with($path, '/api/wifi/status/')) {
            return [['wifi_status_ip', 120]];
        }

        return [];
    }

    private function tooMany(ServerRequestInterface $request, int $retryAfter): ResponseInterface
    {
        $message = 'Terlalu banyak permintaan. Coba lagi dalam ' . max(1, $retryAfter) . ' detik.';
        if (Http::wantsJson($request) || str_starts_with(Http::path($request), '/api/')) {
            return JsonResponse::error($message, 429, ['retry_after' => $retryAfter])
                ->withHeader('Retry-After', (string) $retryAfter);
        }

        return JsonResponse::html(
            '<!doctype html><meta charset="utf-8"><title>429</title>'
            . '<body style="font-family:system-ui;padding:3rem"><h1>429</h1><p>'
            . e($message) . '</p></body>',
            429
        )->withHeader('Retry-After', (string) $retryAfter);
    }

    private function bodyValue(ServerRequestInterface $request, string $key): ?string
    {
        $parsed = $request->getParsedBody();
        if (is_array($parsed) && isset($parsed[$key])) {
            return (string) $parsed[$key];
        }
        $raw = Http::rawBody($request);
        $json = json_decode($raw, true);
        if (is_array($json) && isset($json[$key])) {
            return (string) $json[$key];
        }

        return null;
    }
}
