<?php

declare(strict_types=1);

namespace Billas\Domain\PaymentGateway;

/**
 * Multi-gateway contract. Implementations own provider-specific request and
 * signature details; the rest of the application only sees normalized data.
 */
interface PaymentProvider
{
    public function name(): string;

    public function isConfigured(): bool;

    /**
     * @param array<string,mixed> $context merchant_ref, amount, description,
     *        customer_name, customer_email, customer_phone, callback_url,
     *        return_url, expires_at
     * @return array{reference:string,qr_string?:?string,payload?:array<string,mixed>,expires_at?:int,checkout_url?:?string}
     */
    public function createTransaction(array $context): array;

    /** @param array<string,string> $headers */
    public function verifyWebhook(array $headers, string $rawBody): bool;

    /**
     * @param array<string,string> $headers
     * @return array{merchant_ref:?string,external_ref:?string,amount:int,status:string,gateway_reference:?string,paid_at:?int,event_key:string,kind:string}
     */
    public function normalizeWebhook(array $headers, string $rawBody): array;

    /**
     * @param array<string,mixed> $context customer, account_type, publicBase
     * @return array{external_ref:string,qr_string?:?string,payload?:array<string,mixed>}
     */
    public function createPersistentAccount(array $context): array;

    public function supportsPersistent(string $accountType = 'va'): bool;
}
