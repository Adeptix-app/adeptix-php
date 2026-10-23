<?php

declare(strict_types=1);

namespace Adeptix;

/**
 * Reading crypto payment requests. See https://docs.adeptix.app/crypto-payments#list
 */
class CryptoPaymentRequests
{
    /**
     * Every request status this SDK version knows. "matched": paid (the exact quoted amount arrived,
     * or slightly more and it was matched by tolerance matching; the extra is not refunded
     * automatically); "underpaid": slightly less arrived and was matched by tolerance matching (you
     * were credited what arrived; you decide). Treat a status you do not know as not paid.
     */
    public const STATUSES = ['pending', 'matched', 'expired', 'underpaid'];

    /** Statuses in which a deposit was matched and credited ("matched" is paid; "underpaid" is for you to decide). */
    public const PAID_STATUSES = ['matched', 'underpaid'];

    private AdeptixClient $client;

    public function __construct(AdeptixClient $client)
    {
        $this->client = $client;
    }

    /**
     * Your payment requests, newest first (each in the same shape as get()), for the mode of the key.
     * No pagination cursors: narrow with `status` (one of self::STATUSES), `order_ref` and
     * `created_after` (an ISO 8601 string or a DateTimeInterface); `limit` is 1-100, default 25.
     *
     * @param array{status?: string, order_ref?: string, created_after?: string|\DateTimeInterface, limit?: int} $params
     * @return array<int, array<string, mixed>>
     */
    public function list(array $params = []): array
    {
        $query = [];
        if (!empty($params['status'])) {
            $query['status'] = $params['status'];
        }
        if (!empty($params['order_ref'])) {
            $query['order_ref'] = $params['order_ref'];
        }
        if (isset($params['created_after'])) {
            $after = $params['created_after'];
            $query['created_after'] = $after instanceof \DateTimeInterface ? $after->format(\DateTimeInterface::ATOM) : (string) $after;
        }
        if (isset($params['limit'])) {
            $query['limit'] = (string) $params['limit'];
        }
        $path = '/crypto/payment-requests' . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
        $response = $this->client->request('GET', $path);
        return isset($response['data']) && is_array($response['data']) ? $response['data'] : [];
    }

    /**
     * Same as CryptoPayments::getPaymentRequest().
     *
     * @return array<string, mixed>
     */
    public function get(string $paymentRequestId): array
    {
        return $this->client->request('GET', '/crypto/payment-requests/' . rawurlencode($paymentRequestId));
    }
}
