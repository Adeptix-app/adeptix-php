<?php

declare(strict_types=1);

namespace Adeptix;

/**
 * Discovery for the Merchant Payments API: the providers you can pass as `provider` to
 * Payments::create(). See https://docs.adeptix.app/discovery
 */
class Providers
{
    private AdeptixClient $client;

    public function __construct(AdeptixClient $client)
    {
        $this->client = $client;
    }

    /**
     * The providers Payments::create() accepts for your account right now.
     *
     * @return array<int, array<string, mixed>> each: id, name, minimum_amount, minimum_currency, payment_methods, countries, status
     */
    public function list(): array
    {
        $response = $this->client->request('GET', '/providers');
        return $response['data'] ?? [];
    }

    /**
     * One provider. Throws AdeptixApiException (error code `not_found`) if it isn't usable on your account.
     *
     * @return array<string, mixed>
     */
    public function get(string $providerId): array
    {
        return $this->client->request('GET', '/providers/' . rawurlencode($providerId));
    }
}
