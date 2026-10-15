<?php

declare(strict_types=1);

namespace Adeptix;

/**
 * The Merchant Payments API - hosted card/bank/wallet checkout via whichever regulated providers
 * are enabled on your account. See https://docs.adeptix.app/merchant-payments
 */
class Payments
{
    private AdeptixClient $client;

    public function __construct(AdeptixClient $client)
    {
        $this->client = $client;
    }

    /**
     * Open a hosted checkout session with a provider. POST - never auto-retried by the client;
     * pass `idempotency_key` to retry it yourself safely (the same key with the same request
     * returns the original response instead of creating a duplicate).
     *
     * Optional: `description` (<= 500 chars), `return_url` / `cancel_url` (absolute https URLs;
     * stored and echoed back - a live card/wallet payer is NOT redirected to them automatically,
     * rely on the webhook or get()), `metadata` (up to 20 string keys with string values), and
     * `idempotency_key` (1-255 printable ASCII characters; generate it once per logical payment).
     *
     * @param array{amount: string, currency: string, email: string, provider: string, order_ref?: string, description?: string, return_url?: string, cancel_url?: string, metadata?: array<string, string>, idempotency_key?: string} $params
     * @return array<string, mixed> transaction_id, payment_url, order_ref, return_url, cancel_url, metadata, description
     */
    public function create(array $params): array
    {
        return $this->client->request('POST', '/payment-links', [
            'amount' => $params['amount'],
            'currency' => $params['currency'],
            'email' => $params['email'],
            'provider' => $params['provider'],
            'order_ref' => $params['order_ref'] ?? null,
            'description' => $params['description'] ?? null,
            'return_url' => $params['return_url'] ?? null,
            'cancel_url' => $params['cancel_url'] ?? null,
            // An empty PHP array would encode as a JSON list, not an object - omit it instead.
            'metadata' => !empty($params['metadata']) ? $params['metadata'] : null,
        ], $params['idempotency_key'] ?? null);
    }

    /**
     * Check the status of a previously created payment.
     *
     * @return array<string, mixed> transaction_id, status, order_ref, currency, amount, provider_id, asset, value_coin, txid_out, created_at, paid_at, return_url, cancel_url, metadata, description
     */
    public function get(string $transactionId): array
    {
        return $this->client->request('GET', '/payments/' . rawurlencode($transactionId));
    }
}
