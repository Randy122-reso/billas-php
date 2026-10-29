<?php

declare(strict_types=1);

namespace Billas\Domain\PaymentGateway;

use Billas\Domain\QrisUtil;
use Billas\Support\Env;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use RuntimeException;

abstract class BaseProvider implements PaymentProvider
{
    protected Client $http;

    public function __construct(
        protected readonly Env $env,
        protected readonly LoggerInterface $logger,
    ) {
        $this->http = new Client([
            'timeout' => 15,
            'connect_timeout' => 8,
            'http_errors' => false,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'Billas/4.0',
            ],
        ]);
    }

    /** @param array<string,mixed> $body */
    protected function postJson(string $url, array $body, array $headers = []): array
    {
        return $this->request('POST', $url, ['json' => $body, 'headers' => $headers]);
    }

    /** @param array<string,mixed> $options */
    protected function request(string $method, string $url, array $options = []): array
    {
        try {
            $response = $this->http->request($method, $url, $options);
        } catch (GuzzleException $e) {
            throw new RuntimeException($this->name() . ' HTTP error: ' . $e->getMessage(), 0, $e);
        }

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            $decoded = ['raw' => mb_substr($body, 0, 1000)];
        }
        if ($status >= 400) {
            $message = $decoded['message'] ?? $decoded['error'] ?? $body;
            throw new RuntimeException($this->name() . ' API ' . $status . ': ' . mb_substr((string) $message, 0, 500));
        }

        return $decoded;
    }

    protected function callbackUrl(): string
    {
        return rtrim($this->env->string('APP_URL'), '/') . '/api/webhook/' . $this->name();
    }

    protected function amountInt(mixed $amount): int
    {
        if (is_string($amount)) {
            $amount = str_replace(',', '.', $amount);
        }

        return (int) round((float) $amount);
    }

    /**
     * Most Indonesian gateways do not expose a truly reusable dynamic QRIS.
     * Billas therefore stores a static fallback account, clearly flagged, and
     * reconciles those payments manually. Providers that gain reusable QRIS can
     * override this method.
     *
     * @param array<string,mixed> $context
     * @return array{external_ref:string,qr_string?:?string,payload?:array<string,mixed>}
     */
    public function createPersistentAccount(array $context): array
    {
        if (!$this->env->bool('QRIS_STATIC_ENABLED', true)) {
            throw new RuntimeException($this->name() . ' belum mendukung QRIS persisten dan QRIS statis dinonaktifkan.');
        }

        $customer = $context['customer'] ?? [];
        $customerId = (int) ($customer['id'] ?? 0);
        $code = preg_replace('/[^A-Za-z0-9]/', '', (string) ($customer['code'] ?? 'CUS' . $customerId));
        $externalRef = strtoupper($this->name()) . '-QRIS-' . $code;

        $payload = $this->env->string('QRIS_STATIC_PAYLOAD');
        if ($payload === '') {
            $payload = QrisUtil::demoPayload(
                $this->env->string('QRIS_STATIC_MERCHANT_ID'),
                $this->env->string('QRIS_STATIC_MERCHANT_NAME'),
                $this->env->string('QRIS_STATIC_CITY'),
                $this->env->string('QRIS_STATIC_POSTAL')
            );
        } else {
            $payload = QrisUtil::withoutAmount($payload);
        }

        return [
            'external_ref' => $externalRef,
            'qr_string' => $payload,
            'payload' => [
                'mode' => 'static_fallback',
                'gateway' => $this->name(),
                'note' => 'QRIS statis; pembayaran dicocokkan manual/admin.',
            ],
        ];
    }

    public function supportsPersistent(string $accountType = 'va'): bool
    {
        return $accountType === 'qris' && $this->env->bool('QRIS_STATIC_ENABLED', true);
    }

    /**
     * Fitur F (v4.2): optional provider hook. Providers that do not override
     * this method produce a clear error in the admin simulator.
     *
     * @param array<string,mixed> $target
     * @return array{body:array<string,mixed>,headers:array<string,string>,body_format?:string}
     */
    public function buildSimulatedWebhook(array $target, string $event, ?int $amountOverride = null): array
    {
        throw new \LogicException(static::class . ' belum mendukung simulator.');
    }

    /**
     * Normalizer used only for simulated bypass dispatches. Providers with a
     * real webhook format simply reuse their production normalizer.
     *
     * @param array<string,string> $headers
     * @return array{merchant_ref:?string,external_ref:?string,amount:int,status:string,gateway_reference:?string,paid_at:?int,event_key:string,kind:string}
     */
    public function normalizeSimulatedWebhook(array $headers, string $rawBody): array
    {
        return $this->normalizeWebhook($headers, $rawBody);
    }

    /** @param array<string,mixed> $target */
    protected function simulatedRef(array $target): string
    {
        $ref = $target['merchant_ref'] ?? $target['qris_unique_ref'] ?? $target['external_ref'] ?? '';
        if (!is_scalar($ref) || (string) $ref === '') {
            $ref = 'SIM-' . (string) ($target['id'] ?? bin2hex(random_bytes(4)));
        }

        return (string) $ref;
    }

    /** @param array<string,mixed> $target */
    protected function simulatedAmount(array $target, ?int $amountOverride = null): int
    {
        return $amountOverride ?? (int) ($target['amount'] ?? 0);
    }

    /** @param array<string,mixed> $body */
    protected function simulatedJson(array $body): string
    {
        return json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
