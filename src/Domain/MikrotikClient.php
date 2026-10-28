<?php

declare(strict_types=1);

namespace Billas\Domain;

use Billas\Support\Env;
use Psr\Log\LoggerInterface;
use RouterOS\Client;
use RouterOS\Config;
use RouterOS\Query;

/** Small wrapper around the RouterOS API client for testability. */
final class MikrotikClient
{
    public function __construct(
        private readonly Env $env,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isFake(): bool
    {
        return $this->env->bool('MIKROTIK_FAKE');
    }

    /**
     * @param array{host:string,port:int,username:string,password:string,use_tls:bool} $connection
     * @return list<array<string,string>>
     *
     * @throws \RuntimeException ketika RouterOS mengembalikan `!trap` / `!fatal`.
     */
    public function execute(array $connection, Query $query): array
    {
        $config = new Config([
            'host' => $connection['host'],
            'port' => $connection['port'],
            'user' => $connection['username'],
            'pass' => $connection['password'],
            'ssl' => $connection['use_tls'],
            'timeout' => $this->env->int('MIKROTIK_TIMEOUT', 5),
            'socket_timeout' => $this->env->int('MIKROTIK_TIMEOUT', 5),
        ]);

        $client = new Client($config);
        $response = $client->query($query)->read();

        // FIX: RouterOS membalas error lewat `!trap` (operasi ditolak, mis. nilai
        // tidak valid / tidak ditemukan) atau `!fatal` (error yang memutus sesi).
        // Tanpa pengecekan ini, respons error tetap berbentuk array sehingga
        // Billas salah menganggap push sukses (pushed_to_router=1) dan worker
        // menyelesaikan antrean tanpa retry.
        if (is_array($response)) {
            $errorMessage = $this->extractRouterOsError($response);
            if ($errorMessage !== null) {
                $this->logger->error('MikroTik API error', [
                    'host' => $connection['host'],
                    'port' => $connection['port'],
                    'message' => $errorMessage,
                ]);
                throw new \RuntimeException('MikroTik API Error: ' . $errorMessage);
            }
        }

        return is_array($response) ? $response : [];
    }

    /**
     * FIX: normalisasi pesan error RouterOS ke satu string yang bisa dibaca.
     *
     * @param array<string,mixed> $response
     */
    private function extractRouterOsError(array $response): ?string
    {
        if (array_key_exists('!trap', $response)) {
            return $this->flattenErrorValue($response['!trap']);
        }

        if (array_key_exists('!fatal', $response)) {
            return 'Fatal: ' . $this->flattenErrorValue($response['!fatal']);
        }

        // Fallback untuk bentuk respons wrapper lama yang kadang menaruh pesan
        // error di `after.message` (mis. pada implementasi StreamClient tertentu).
        if (isset($response['after']['message'])) {
            return (string) $response['after']['message'];
        }

        return null;
    }

    /**
     * FIX: terima string, single-assoc, atau list of trap. Library kadang
     * mengembalikan `[['message'=>'...']]`, kadang `['message'=>'...']`.
     */
    private function flattenErrorValue(mixed $value): string
    {
        if (is_string($value) || is_numeric($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            $first = $value[0] ?? $value;

            if (is_array($first) && isset($first['message'])) {
                return (string) $first['message'];
            }
            if (is_string($first)) {
                return $first;
            }

            $encoded = json_encode($value);
            return $encoded === false ? 'unknown error' : $encoded;
        }

        return 'unknown error';
    }

    public function logFake(string $operation, array $context = []): void
    {
        $this->logger->info('[MIKROTIK_FAKE] ' . $operation, $context);
    }
}