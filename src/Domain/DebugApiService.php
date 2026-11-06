<?php

declare(strict_types=1);

namespace Billas\Domain;

use Billas\Domain\PaymentGateway\GatewayFactory;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use InvalidArgumentException;
use LogicException;
use PDO;
use RuntimeException;

final class DebugApiService
{
    private const DEFAULT_ALLOWED_METHODS = ['GET', 'POST', 'DELETE'];

    private ?ClientInterface $httpClient = null;

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(
        private readonly PDO $db,
        private readonly GatewayFactory $gateways,
        private readonly AuditService $audit,
        private readonly array $config,
        private readonly ?int $adminId = null,
        ?ClientInterface $httpClient = null,
    ) {
        $this->httpClient = $httpClient;
    }

    /**
     * @return array{status:int, duration_ms:int, headers:array<string,list<string>>, body:string}
     */
    public function send(string $provider, string $method, string $endpoint, ?string $body): array
    {
        $method = strtoupper(trim($method));

        // 1) Validasi method
        if (!in_array($method, ['GET', 'POST', 'DELETE'], true)) {
            throw new InvalidArgumentException("Method tidak valid: {$method}");
        }
        $rawAllowed = (string) ($this->config['api_tester_allowed_methods'] ?? 'GET,POST,DELETE');
        $allowed = array_filter(array_map('trim', explode(',', strtoupper($rawAllowed))));
        if (!in_array($method, $allowed, true)) {
            throw new InvalidArgumentException("Method {$method} tidak diizinkan oleh konfigurasi.");
        }

        // 2) Validasi body
        $maxBodyBytes = (int) ($this->config['api_tester_max_body_bytes'] ?? 65536);
        if ($body !== null && strlen($body) > $maxBodyBytes) {
            throw new InvalidArgumentException('Body melebihi batas ukuran.');
        }
        if ($body !== null && trim($body) !== '' && $method !== 'GET') {
            try {
                json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new InvalidArgumentException('Body harus berupa JSON valid: ' . $e->getMessage(), 0, $e);
            }
        }

        // 3) Validasi endpoint
        if (preg_match('#^https?://#i', $endpoint)) {
            throw new InvalidArgumentException('Endpoint harus path relatif, bukan URL absolut.');
        }
        if (str_starts_with($endpoint, '//')) {
            throw new InvalidArgumentException('Endpoint tidak valid.');
        }

        $gw = $this->gateways->make($provider);
        if (!method_exists($gw, 'getApiTesterBaseUrl')) {
            throw new LogicException("Provider {$provider} tidak mendukung API tester.");
        }
        try {
            $baseUrl = $gw->getApiTesterBaseUrl();
        } catch (LogicException $e) {
            throw $e;
        }

        $allowedPaths = method_exists($gw, 'getApiTesterAllowedPaths') ? $gw->getApiTesterAllowedPaths() : [];
        $trimmedEndpoint = ltrim($endpoint, '/');
        $matched = false;
        foreach ($allowedPaths as $pattern) {
            if (preg_match($pattern, $trimmedEndpoint) || preg_match($pattern, $endpoint)) {
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            throw new InvalidArgumentException("Endpoint tidak di whitelist untuk provider {$provider}.");
        }

        // 4) Bangun URL & headers (server-side)
        $url = rtrim($baseUrl, '/') . '/' . $trimmedEndpoint;
        $headers = method_exists($gw, 'getApiTesterHeaders') ? $gw->getApiTesterHeaders() : [];
        $secrets = method_exists($gw, 'getApiTesterSecrets') ? $gw->getApiTesterSecrets() : [];

        // 5) Kirim request
        $timeout = (int) ($this->config['api_tester_timeout_seconds'] ?? 10);
        $client = $this->httpClient ?? new Client([
            'timeout'         => $timeout,
            'connect_timeout' => min(5, $timeout),
            'http_errors'     => false,
            'allow_redirects' => false,
        ]);

        $options = [
            'headers' => $headers,
        ];
        if ($body !== null && $body !== '') {
            $options['body'] = $body;
        }

        $start = microtime(true);
        try {
            $response = $client->request($method, $url, $options);
        } catch (GuzzleException $e) {
            $duration = (int) ((microtime(true) - $start) * 1000);
            $this->recordRun($provider, $method, $endpoint, $body, null, null, $e->getMessage(), $duration, $secrets);
            throw new RuntimeException('Request gagal: ' . $e->getMessage(), 0, $e);
        }
        $duration = (int) ((microtime(true) - $start) * 1000);

        // 6) Mask & simpan
        $statusCode    = $response->getStatusCode();
        $maskedHeaders = $this->maskHeaders($response->getHeaders());
        $respBody      = (string) $response->getBody();
        $maskedBody    = $this->maskBody($respBody, $secrets);
        $maskedReqBody = $body !== null ? $this->maskBody($body, $secrets) : null;

        $this->recordRun($provider, $method, $endpoint, $maskedReqBody, $statusCode, $maskedBody, null, $duration, []);
        $this->audit->record(
            'admin',
            (string) ($this->adminId ?? 'system'),
            'api_tester.dispatch',
            'api_tester_run',
            null,
            "{$provider}:{$method}",
            $endpoint
        );

        return [
            'status'      => $statusCode,
            'duration_ms' => $duration,
            'headers'     => $maskedHeaders,
            'body'        => $maskedBody,
        ];
    }

    /**
     * @param array<string,list<string>> $headers
     * @return array<string,list<string>>
     */
    public function maskHeaders(array $headers): array
    {
        $sensitive = [
            'authorization',
            'xendit-api-key',
            'x-callback-token',
            'x-callback-signature',
            'x-signature-key',
            'x-signature',
        ];
        $out = [];
        foreach ($headers as $name => $values) {
            $out[$name] = in_array(strtolower($name), $sensitive, true)
                ? ['***MASKED***']
                : $values;
        }

        return $out;
    }

    /**
     * @param list<string> $secrets
     */
    public function maskBody(string $body, array $secrets): string
    {
        foreach ($secrets as $s) {
            if ($s !== null && $s !== '') {
                $body = str_replace($s, '***MASKED***', $body);
            }
        }

        return $body;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function recentRuns(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->db->prepare(
            'SELECT r.id, r.provider, r.method, r.endpoint, r.response_status, r.duration_ms,
                    r.error, r.created_by, r.created_at, u.username AS creator_username
             FROM api_tester_runs r
             LEFT JOIN users u ON u.id = r.created_by
             ORDER BY r.id DESC
             LIMIT ?'
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param list<string> $secrets
     */
    public function recordRun(
        string $provider,
        string $method,
        string $endpoint,
        ?string $requestBody,
        ?int $status,
        ?string $responseBody,
        ?string $error,
        int $durationMs,
        array $secrets
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO api_tester_runs
             (provider, method, endpoint, request_body, response_status, response_body,
              duration_ms, error, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $provider,
            $method,
            $endpoint,
            $requestBody !== null ? $this->maskBody($requestBody, $secrets) : null,
            $status,
            $responseBody !== null ? $this->maskBody($responseBody, $secrets) : null,
            $error !== null ? $this->maskBody($error, $secrets) : null,
            $durationMs,
            $this->adminId,
            time(),
        ]);
    }
}
