<?php

declare(strict_types=1);

namespace Adeptix\Tests;

use Adeptix\AdeptixClient;
use Adeptix\Exceptions\AdeptixApiException;
use PHPUnit\Framework\TestCase;

final class CryptoPhase0Test extends TestCase
{
    /**
     * @param array<int, array{0: int, 1: array<string, mixed>}> $replies
     * @param array<int, array{0: string, 1: string, 2: array<int, string>, 3: ?string}> $calls
     */
    private function client(array $replies, array &$calls): AdeptixClient
    {
        $transport = function (string $method, string $url, array $headers, ?string $payload) use (&$replies, &$calls) {
            $calls[] = [$method, $url, $headers, $payload];
            [$status, $body] = count($replies) > 1 ? array_shift($replies) : $replies[0];
            return [$status, json_encode($body)];
        };
        return new AdeptixClient('ak_live_test', AdeptixClient::DEFAULT_BASE_URL, 15, 0, $transport);
    }

    public function testExpiresInMinutesIsSentOnlyWhenGiven(): void
    {
        $calls = [];
        $client = $this->client([[201, ['payment_request_id' => 'cpr_1']]], $calls);
        $client->crypto()->createPaymentRequest(['chain' => 'polygon', 'token' => 'USDC', 'amount' => '49.00', 'expires_in_minutes' => 30]);
        $client->crypto()->createPaymentRequest(['chain' => 'polygon', 'token' => 'USDC', 'amount' => '49.00']);
        $this->assertSame(['chain' => 'polygon', 'token' => 'USDC', 'amount' => '49.00', 'expires_in_minutes' => 30], json_decode((string) $calls[0][3], true));
        $this->assertSame(['chain' => 'polygon', 'token' => 'USDC', 'amount' => '49.00'], json_decode((string) $calls[1][3], true));
    }

    public function testAmountBelowMinimumExposesTheMinimum(): void
    {
        $calls = [];
        $client = $this->client([
            [400, ['error' => 'amount_below_minimum', 'message' => 'The minimum amount for USDT on this network is 1.00.', 'minimum_amount' => '1.00']],
            [400, ['error' => 'invalid_expiry', 'message' => 'expires_in_minutes must be a whole number of minutes from 10 to 60.']],
        ], $calls);
        try {
            $client->crypto()->createPaymentRequest(['chain' => 'tron', 'token' => 'USDT', 'amount' => '0.5']);
            $this->fail('expected an exception');
        } catch (AdeptixApiException $e) {
            $this->assertSame('amount_below_minimum', $e->getErrorCode());
            $this->assertSame('1.00', $e->getMinimumAmount());
        }
        try {
            $client->crypto()->createPaymentRequest(['chain' => 'tron', 'token' => 'USDT', 'amount' => '5', 'expires_in_minutes' => 5]);
            $this->fail('expected an exception');
        } catch (AdeptixApiException $e) {
            $this->assertSame('invalid_expiry', $e->getErrorCode());
            $this->assertNull($e->getMinimumAmount());
        }
        $this->assertContains('invalid_expiry', AdeptixApiException::KNOWN_ERROR_CODES);
        $this->assertContains('amount_below_minimum', AdeptixApiException::KNOWN_ERROR_CODES);
    }

    public function testPaymentRequestsListBuildsTheQuery(): void
    {
        $calls = [];
        $row = ['payment_request_id' => 'cpr_1', 'status' => 'matched', 'payment' => ['credited_amount' => '9.95']];
        $client = $this->client([[200, ['data' => [$row]]], [200, ['data' => []]], [200, $row]], $calls);
        $rows = $client->crypto()->paymentRequests()->list([
            'status' => 'matched',
            'order_ref' => 'o 1',
            'created_after' => new \DateTimeImmutable('2026-10-01T00:00:00Z'),
            'limit' => 10,
        ]);
        $this->assertSame([$row], $rows);
        $this->assertSame([], $client->crypto()->paymentRequests()->list());
        $this->assertSame($row, $client->crypto()->paymentRequests()->get('cpr_1'));
        $this->assertSame(
            [
                'https://api.adeptix.app/v1/crypto/payment-requests?status=matched&order_ref=o%201&created_after=2026-10-01T00%3A00%3A00%2B00%3A00&limit=10',
                'https://api.adeptix.app/v1/crypto/payment-requests',
                'https://api.adeptix.app/v1/crypto/payment-requests/cpr_1',
            ],
            array_map(static fn ($c) => $c[1], $calls)
        );
    }
}
