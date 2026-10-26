<?php

declare(strict_types=1);

namespace Billas\Domain\PaymentGateway;

use Billas\Support\Env;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class GatewayFactory
{
    /** @var array<string,class-string<PaymentProvider>> */
    public const PROVIDERS = [
        'tripay' => TripayService::class,
        'midtrans' => MidtransService::class,
        'xendit' => XenditService::class,
        'duitku' => DuitkuService::class,
        'qris_static' => QrisStaticService::class,
    ];

    /** @var array<string,PaymentProvider> */
    private array $instances = [];

    public function __construct(
        private readonly Env $env,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function make(string $name): PaymentProvider
    {
        $name = strtolower(trim($name));
        if (!isset(self::PROVIDERS[$name])) {
            throw new RuntimeException('Gateway tidak dikenal: ' . $name);
        }
        if (!isset($this->instances[$name])) {
            $class = self::PROVIDERS[$name];
            $this->instances[$name] = new $class($this->env, $this->logger);
        }

        return $this->instances[$name];
    }

    public function configured(string $name): bool
    {
        try {
            return $this->make($name)->isConfigured();
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return list<PaymentProvider> */
    public function chain(?string $preferred = null): array
    {
        $names = [];
        if ($preferred !== null && $preferred !== '') {
            $names[] = $preferred;
        }
        foreach ($this->env->csv('PAYMENT_FALLBACK_CHAIN', 'qris_static') as $name) {
            $names[] = $name;
        }
        $names[] = 'qris_static';

        $result = [];
        $seen = [];
        foreach (array_unique($names) as $name) {
            if (isset($seen[$name]) || !isset(self::PROVIDERS[$name])) {
                continue;
            }
            $seen[$name] = true;
            $provider = $this->make($name);
            if ($provider->isConfigured()) {
                $result[] = $provider;
            }
        }

        return $result;
    }

    /** @return list<string> */
    public function configuredNames(): array
    {
        $result = [];
        foreach (array_keys(self::PROVIDERS) as $name) {
            if ($this->configured($name)) {
                $result[] = $name;
            }
        }

        return $result;
    }

    /** @return list<string> */
    public function persistentNames(string $accountType = 'qris'): array
    {
        $configured = $this->configuredNames();
        $allowed = $this->env->csv('PPPOE_QRIS_UNIQUE_PROVIDERS', 'midtrans,xendit,duitku');
        $result = [];
        foreach ($allowed as $name) {
            if (in_array($name, $configured, true) && $this->make($name)->supportsPersistent($accountType)) {
                $result[] = $name;
            }
        }

        return $result;
    }
}
