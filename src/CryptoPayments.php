<?php

declare(strict_types=1);

namespace Adeptix;

/**
 * The Crypto Payments API - unique, exact-amount on-chain deposits: USDT on BSC, Polygon or Tron;
 * USDC on BSC or Polygon. See https://docs.adeptix.app/crypto-payments
 */
class CryptoPayments
{
    private AdeptixClient $client;

    public function __construct(AdeptixClient $client)
    {
        $this->client = $client;
    }

    /**
     * Reserve a unique deposit amount and get the address to send it to. POST - never
     * auto-retried by the client; pass `idempotency_key` to retry it yourself safely (the same key
     * with the same request returns the original response instead of creating a duplicate).
     *
     * Optional: `description` (<= 500 chars), `return_url` / `cancel_url` (absolute https URLs, used by
     * the hosted checkout page), `metadata` (up to 20 string keys with string values), and
     * `idempotency_key` (1-255 printable ASCII characters; generate it once per logical payment), and
     * `expires_in_minutes` (how long the customer has to pay: an integer 10-60, default 20; any other
     * value fails with error code `invalid_expiry`). An `amount` below the asset's `min_amount` fails
     * with error code `amount_below_minimum` (see AdeptixApiException::getMinimumAmount()).
     * `pay_to_address` is returned per request and can differ between requests: always show the one
     * from this response.
     *
     * @param array{chain: string, token: string, amount: string, order_ref?: string, customer_email?: string, description?: string, return_url?: string, cancel_url?: string, metadata?: array<string, string>, idempotency_key?: string, expires_in_minutes?: int} $params
     * @return array<string, mixed> payment_request_id, chain, token, pay_to_address, amount, expires_at, checkout_url, order_ref, return_url, cancel_url, metadata, description
     */
    public function createPaymentRequest(array $params): array
    {
        return $this->client->request('POST', '/crypto/payment-requests', [
            'chain' => $params['chain'],
            'token' => $params['token'],
            'amount' => $params['amount'],
            'order_ref' => $params['order_ref'] ?? null,
            'customer_email' => $params['customer_email'] ?? null,
            'description' => $params['description'] ?? null,
            'return_url' => $params['return_url'] ?? null,
            'cancel_url' => $params['cancel_url'] ?? null,
            // An empty PHP array would encode as a JSON list, not an object - omit it instead.
            'metadata' => !empty($params['metadata']) ? $params['metadata'] : null,
            'expires_in_minutes' => $params['expires_in_minutes'] ?? null,
        ], $params['idempotency_key'] ?? null);
    }

    /** Discovery: the chain/token pairs createPaymentRequest accepts right now. */
    public function assets(): CryptoAssets
    {
        return new CryptoAssets($this->client);
    }

    /** Listing and reading payment requests: paymentRequests()->list([...]) and ->get($id). */
    public function paymentRequests(): CryptoPaymentRequests
    {
        return new CryptoPaymentRequests($this->client);
    }

    /**
     * Check the status of a previously created crypto payment request.
     *
     * @return array<string, mixed> payment_request_id, status, order_ref, asset_id, chain, token, pay_to_address, requested_amount, quoted_amount, created_at, expires_at, checkout_url, payment (null, or tx_hash, amount, block_number, detected_at, credited_amount, commission_amount), return_url, cancel_url, metadata, description
     */
    public function getPaymentRequest(string $paymentRequestId): array
    {
        return $this->client->request('GET', '/crypto/payment-requests/' . rawurlencode($paymentRequestId));
    }
}
