<?php

declare(strict_types=1);

namespace Billas\Controller;

use Billas\Domain\WebhookService;
use Billas\Support\Http;
use Billas\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/** Gateway webhook entry point. Always reads the raw body for signature checks. */
final class WebhookController
{
    public function __construct(
        private readonly WebhookService $webhooks,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $provider = strtolower((string) ($args['provider'] ?? ''));
        if ($provider === '') {
            return JsonResponse::error('Provider tidak valid.', 400);
        }

        try {
            $result = $this->webhooks->handle($provider, Http::headers($request), Http::rawBody($request));

            return JsonResponse::ok($result);
        } catch (RuntimeException $e) {
            $this->logger->warning('Webhook ditolak', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);
            $status = str_contains(strtolower($e->getMessage()), 'signature') ? 401 : 422;

            return JsonResponse::error($e->getMessage(), $status);
        } catch (Throwable $e) {
            $this->logger->error('Webhook gagal', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            return JsonResponse::error('Webhook gagal diproses.', 500);
        }
    }
}
