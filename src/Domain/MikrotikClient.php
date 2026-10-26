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

        return is_array($response) ? $response : [];
    }

    public function logFake(string $operation, array $context = []): void
    {
        $this->logger->info('[MIKROTIK_FAKE] ' . $operation, $context);
    }
}
