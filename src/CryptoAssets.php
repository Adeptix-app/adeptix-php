<?php

declare(strict_types=1);

namespace Adeptix;

/**
 * Discovery for the Crypto Payments API: the chain/token pairs accepted by
 * CryptoPayments::createPaymentRequest(). See https://docs.adeptix.app/discovery
 */
class CryptoAssets
{
    private AdeptixClient $client;

    public function __construct(AdeptixClient $client)
    {
        $this->client = $client;
    }

    /**
     * @return array<int, array<string, mixed>> each: id, chain, token, decimals, min_amount, commission_rate_default
     */
    public function list(): array
    {
        $response = $this->client->request('GET', '/crypto/assets');
        return $response['data'] ?? [];
    }
}
