<?php

declare(strict_types=1);

namespace Adeptix\Tests;

use Adeptix\AdeptixClient;
use Adeptix\Exceptions\AdeptixApiException;
use PHPUnit\Framework\TestCase;

final class ClientV2Test extends TestCase
{
    /** @param array<int, array{0: string, 1: string, 2: array<int, string>, 3: ?string}> $calls */
    private function recordingClient(int $status, array $body, array &$calls, int $maxRetries = 2): AdeptixClient
    {
        $transport = function (string $method, string $url, array $headers, ?string $payload) use ($status, $body, &$calls) {
            $calls[] = [$method, $url, $headers, $payload];
            return [$status, json_encode($body)];
        };
        return new AdeptixClient('ak_live_test', AdeptixClient::DEFAULT_BASE_URL, 15, $maxRetries, $transport);
    }

    public function testPaymentsCreateSendsTheIdempotencyKeyAndNewFieldsOnlyWhenGiven(): void
    {
        $calls = [];
        $client = $this->recordingClient(201, ['transaction_id' => 'txn_1', 'payment_url' => 'https://x'], $calls);

        $client->payments()->create([
            'amount' => '10.00',
            'currency' => 'USD',
            'email' => 'a@b.com',
            'provider' => 'stripe',
            'order_ref' => 'o1',
            'return_url' => 'https://shop.example.com/r',
            'description' => 'd',
            'metadata' => ['a' => 'b'],
            'idempotency_key' => 'key-1',
        ]);
        $this->assertContains('Idempotency-Key: key-1', $calls[0][2]);
        $this->assertSame(
            [
                'amount' => '10.00',
                'currency' => 'USD',
                'email' => 'a@b.com',
                'provider' => 'stripe',
                'order_ref' => 'o1',
                'description' => 'd',
                'return_url' => 'https://shop.example.com/r',
                'metadata' => ['a' => 'b'],
            ],
            json_decode((string) $calls[0][3], true)
        );

        $client->payments()->create(['amount' => '10.00', 'currency' => 'USD', 'email' => 'a@b.com', 'provider' => 'stripe', 'metadata' => []]);
        foreach ($calls[1][2] as $header) {
            $this->assertStringStartsNotWith('Idempotency-Key', $header);
        }
        // An empty metadata array is omitted rather than sent as a JSON list.
        $this->assertSame(
            ['amount' => '10.00', 'currency' => 'USD', 'email' => 'a@b.com', 'provider' => 'stripe'],
            json_decode((string) $calls[1][3], true)
        );
    }

    public function testCryptoCreatePassesTheKeyAndCheckoutFields(): void
    {
        $calls = [];
        $client = $this->recordingClient(201, ['payment_request_id' => 'cpr_1', 'checkout_url' => 'https://dash.adeptix.app/pay/crypto/cpr_1'], $calls);
        $result = $client->crypto()->createPaymentRequest([
            'chain' => 'tron',
            'token' => 'USDT',
            'amount' => '5.00',
            'cancel_url' => 'https://shop.example.com/c',
            'idempotency_key' => 'k2',
        ]);
        $this->assertContains('Idempotency-Key: k2', $calls[0][2]);
        $this->assertSame(
            ['chain' => 'tron', 'token' => 'USDT', 'amount' => '5.00', 'cancel_url' => 'https://shop.example.com/c'],
            json_decode((string) $calls[0][3], true)
        );
        $this->assertSame('https://dash.adeptix.app/pay/crypto/cpr_1', $result['checkout_url']);
    }

    public function testAPostWithAnIdempotencyKeyIsStillNeverAutoRetried(): void
    {
        $calls = [];
        $client = $this->recordingClient(500, ['error' => 'internal_error'], $calls, 3);
        try {
            $client->payments()->create(['amount' => '1', 'currency' => 'USD', 'email' => 'a@b.com', 'provider' => 'stripe', 'idempotency_key' => 'k']);
            $this->fail('Expected an exception');
        } catch (AdeptixApiException $e) {
            $this->assertSame('internal_error', $e->getErrorCode());
        }
        $this->assertCount(1, $calls);
    }

    public function testTheNewErrorCodesSurfaceOnTheException(): void
    {
        $calls = [];
        $client = $this->recordingClient(409, ['error' => 'idempotent_request_in_progress'], $calls);
        try {
            $client->payments()->create(['amount' => '1', 'currency' => 'USD', 'email' => 'a@b.com', 'provider' => 'stripe', 'idempotency_key' => 'k']);
            $this->fail('Expected an exception');
        } catch (AdeptixApiException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertSame('idempotent_request_in_progress', $e->getErrorCode());
        }
    }

    public function testProvidersListAndGet(): void
    {
        $calls = [];
        $provider = ['id' => 'stripe', 'name' => 'Stripe', 'minimum_amount' => '2', 'minimum_currency' => 'USD', 'payment_methods' => null, 'countries' => null, 'status' => 'active'];
        $client = $this->recordingClient(200, ['data' => [$provider]], $calls);
        $this->assertSame([$provider], $client->providers()->list());
        $this->assertSame('GET', $calls[0][0]);
        $this->assertSame('https://api.adeptix.app/v1/providers', $calls[0][1]);

        $calls = [];
        $client = $this->recordingClient(200, $provider, $calls);
        $this->assertSame($provider, $client->providers()->get('stripe'));
        $this->assertSame('https://api.adeptix.app/v1/providers/stripe', $calls[0][1]);
    }

    public function testCryptoAssetsList(): void
    {
        $calls = [];
        $asset = ['id' => 'tron_usdt', 'chain' => 'tron', 'token' => 'USDT', 'decimals' => 6, 'min_amount' => '1.00', 'commission_rate_default' => 0.005];
        $client = $this->recordingClient(200, ['data' => [$asset]], $calls);
        $this->assertSame([$asset], $client->crypto()->assets()->list());
        $this->assertSame('https://api.adeptix.app/v1/crypto/assets', $calls[0][1]);
    }
}
