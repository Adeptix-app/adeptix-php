<?php

declare(strict_types=1);

namespace Adeptix\Tests;

use Adeptix\AdeptixClient;
use Adeptix\Exceptions\AdeptixApiException;
use PHPUnit\Framework\TestCase;

final class AdeptixClientTest extends TestCase
{
    private function jsonTransport(int $status, array $body): callable
    {
        return function (string $method, string $url, array $headers, ?string $payload) use ($status, $body) {
            return [$status, json_encode($body)];
        };
    }

    public function testCreatePaymentRequestSendsTheRightRequestAndParsesTheResponse(): void
    {
        $capturedMethod = null;
        $capturedUrl = null;
        $capturedHeaders = null;
        $capturedPayload = null;

        $transport = function (string $method, string $url, array $headers, ?string $payload) use (
            &$capturedMethod,
            &$capturedUrl,
            &$capturedHeaders,
            &$capturedPayload
        ) {
            $capturedMethod = $method;
            $capturedUrl = $url;
            $capturedHeaders = $headers;
            $capturedPayload = $payload;
            return [201, json_encode([
                'payment_request_id' => 'cpr_test',
                'chain' => 'polygon',
                'token' => 'USDC',
                'pay_to_address' => '0xabc',
                'amount' => '49.0001',
                'expires_at' => '2026-01-01T00:00:00.000Z',
            ])];
        };

        $client = new AdeptixClient('ak_live_test', AdeptixClient::DEFAULT_BASE_URL, 15, 2, $transport);
        $result = $client->crypto()->createPaymentRequest([
            'chain' => 'polygon',
            'token' => 'USDC',
            'amount' => '49.00',
            'order_ref' => 'order_1',
        ]);

        $this->assertSame('POST', $capturedMethod);
        $this->assertSame('https://api.adeptix.app/v1/crypto/payment-requests', $capturedUrl);
        $this->assertContains('Authorization: Bearer ak_live_test', $capturedHeaders);
        $this->assertSame(
            ['chain' => 'polygon', 'token' => 'USDC', 'amount' => '49.00', 'order_ref' => 'order_1'],
            json_decode((string) $capturedPayload, true)
        );
        $this->assertSame('cpr_test', $result['payment_request_id']);
        $this->assertSame('49.0001', $result['amount']);
    }

    public function testGetPaymentRequestIssuesAGetToTheRightPath(): void
    {
        $capturedMethod = null;
        $capturedUrl = null;
        $transport = function (string $method, string $url, array $headers, ?string $payload) use (&$capturedMethod, &$capturedUrl) {
            $capturedMethod = $method;
            $capturedUrl = $url;
            return [200, json_encode(['payment_request_id' => 'cpr_abc', 'status' => 'pending'])];
        };

        $client = new AdeptixClient('ak_live_test', AdeptixClient::DEFAULT_BASE_URL, 15, 2, $transport);
        $result = $client->crypto()->getPaymentRequest('cpr_abc');

        $this->assertSame('GET', $capturedMethod);
        $this->assertSame('https://api.adeptix.app/v1/crypto/payment-requests/cpr_abc', $capturedUrl);
        $this->assertSame('pending', $result['status']);
    }

    public function testPaymentsCreateSendsTheRightRequest(): void
    {
        $client = new AdeptixClient(
            'ak_live_test',
            AdeptixClient::DEFAULT_BASE_URL,
            15,
            2,
            $this->jsonTransport(201, ['transaction_id' => 'txn_test', 'payment_url' => 'https://example.com/checkout'])
        );

        $result = $client->payments()->create([
            'amount' => '10.00',
            'currency' => 'usd',
            'email' => 'a@b.com',
            'provider' => 'stripe',
        ]);

        $this->assertSame('txn_test', $result['transaction_id']);
        $this->assertSame('https://example.com/checkout', $result['payment_url']);
    }

    public function testPaymentsGetReturnsStatus(): void
    {
        $client = new AdeptixClient(
            'ak_live_test',
            AdeptixClient::DEFAULT_BASE_URL,
            15,
            2,
            $this->jsonTransport(200, ['transaction_id' => 'txn_test', 'status' => 'paid'])
        );

        $result = $client->payments()->get('txn_test');
        $this->assertSame('paid', $result['status']);
    }

    public function testThrowsAdeptixApiExceptionWithTheCodeAndDetailsFromA400Response(): void
    {
        $client = new AdeptixClient(
            'ak_live_test',
            AdeptixClient::DEFAULT_BASE_URL,
            15,
            2,
            $this->jsonTransport(400, ['error' => 'invalid_request', 'details' => ['fieldErrors' => ['amount' => ['bad']]]])
        );

        try {
            $client->payments()->create(['amount' => 'bad', 'currency' => 'USD', 'email' => 'a@b.com', 'provider' => 'stripe']);
            $this->fail('Expected an AdeptixApiException to be thrown');
        } catch (AdeptixApiException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('invalid_request', $e->getErrorCode());
            $this->assertSame(['fieldErrors' => ['amount' => ['bad']]], $e->getDetails());
        }
    }

    public function testAPostIsNeverRetriedOnA500(): void
    {
        $callCount = 0;
        $transport = function () use (&$callCount) {
            $callCount++;
            return [500, json_encode(['error' => 'internal_error'])];
        };

        $client = new AdeptixClient('ak_live_test', AdeptixClient::DEFAULT_BASE_URL, 15, 3, $transport);

        try {
            $client->payments()->create(['amount' => '1', 'currency' => 'USD', 'email' => 'a@b.com', 'provider' => 'stripe']);
            $this->fail('Expected an exception');
        } catch (AdeptixApiException $e) {
            // expected
        }

        $this->assertSame(1, $callCount);
    }

    public function testAGetIsRetriedOnA500UpToMaxRetriesThenSucceeds(): void
    {
        $callCount = 0;
        $transport = function () use (&$callCount) {
            $callCount++;
            if ($callCount < 3) {
                return [500, json_encode(['error' => 'internal_error'])];
            }
            return [200, json_encode(['transaction_id' => 'txn_test', 'status' => 'paid'])];
        };

        $client = new AdeptixClient('ak_live_test', AdeptixClient::DEFAULT_BASE_URL, 15, 3, $transport);
        $result = $client->payments()->get('txn_test');

        $this->assertSame(3, $callCount);
        $this->assertSame('paid', $result['status']);
    }

    public function testAGetGivesUpAfterMaxRetriesAndThrowsTheLastError(): void
    {
        $callCount = 0;
        $transport = function () use (&$callCount) {
            $callCount++;
            return [503, json_encode(['error' => 'platform_not_configured'])];
        };

        $client = new AdeptixClient('ak_live_test', AdeptixClient::DEFAULT_BASE_URL, 15, 2, $transport);

        try {
            $client->payments()->get('txn_test');
            $this->fail('Expected an exception');
        } catch (AdeptixApiException $e) {
            $this->assertSame('platform_not_configured', $e->getErrorCode());
        }

        $this->assertSame(3, $callCount); // initial + 2 retries
    }
}
