<?php

declare(strict_types=1);

namespace Adeptix\Tests;

use Adeptix\CryptoPaymentRequests;
use Adeptix\Webhooks;
use PHPUnit\Framework\TestCase;

// Tolerance matching (Unreleased): status underpaid with the crypto_payment.underpaid event, and an
// overpayment within tolerance as a paid crypto_payment.matched with overpaid: true.
final class ToleranceEventsTest extends TestCase
{
    private const SECRET = 'whsec_test_secret';

    public function testConstants(): void
    {
        $this->assertSame(['pending', 'matched', 'expired', 'underpaid'], CryptoPaymentRequests::STATUSES);
        $this->assertSame(['matched', 'underpaid'], CryptoPaymentRequests::PAID_STATUSES);
        $this->assertContains('crypto_payment.underpaid', Webhooks::EVENT_TYPES);
        $this->assertNotContains('crypto_payment.overpaid', Webhooks::EVENT_TYPES);
        $this->assertCount(5, Webhooks::EVENT_TYPES);
    }

    public function testConstructEventReturnsTheUnderpaidFields(): void
    {
        $body = json_encode([
            'event' => 'crypto_payment.underpaid',
            'id' => 'evt_e2b7c94a1d0f4e3b9a6c58d7f1e2a304',
            'created' => 1790000600,
            'api_version' => '2026-10-15',
            'payment_request_id' => 'cpr_7ae03d5b1c9e4f2a8d6b0c3e1f4a7b92',
            'order_ref' => 'order_10231',
            'status' => 'underpaid',
            'asset_id' => 'polygon_usdc',
            'requested_amount' => '49.00',
            'expected_amount' => '49.0137',
            'received_amount' => '48.0137',
            'difference' => '-1',
            'difference_pct' => '-2.04',
            'match_method' => 'tolerance',
            'tx_hash' => '0xabc',
            'test_mode' => false,
            'metadata' => null,
            'description' => null,
        ]);
        $now = time();
        $header = 't=' . $now . ',v1=' . hash_hmac('sha256', $now . '.' . $body, self::SECRET);
        $event = Webhooks::constructEvent($body, $header, self::SECRET);
        $this->assertSame('crypto_payment.underpaid', $event['event']);
        $this->assertContains($event['status'], CryptoPaymentRequests::PAID_STATUSES);
        $this->assertNotSame('matched', $event['status']);
        $this->assertSame('48.0137', $event['received_amount']);
        $this->assertSame('-2.04', $event['difference_pct']);
    }

    public function testOverpaymentWithinToleranceIsAPaidMatchedEvent(): void
    {
        $body = json_encode([
            'event' => 'crypto_payment.matched',
            'id' => 'evt_4c9d2e7f0a1b4c83b6e5d9a2f7c1e086',
            'created' => 1790000600,
            'api_version' => '2026-10-15',
            'payment_request_id' => 'cpr_7ae03d5b1c9e4f2a8d6b0c3e1f4a7b92',
            'order_ref' => 'order_10231',
            'status' => 'matched',
            'asset_id' => 'polygon_usdc',
            'quoted_amount' => '49.0137',
            'amount' => '50.0137',
            'tx_hash' => '0xabc',
            'test_mode' => false,
            'metadata' => null,
            'description' => null,
            'overpaid' => true,
            'expected_amount' => '49.0137',
            'received_amount' => '50.0137',
            'difference' => '1',
            'difference_pct' => '2.04',
            'match_method' => 'tolerance',
        ]);
        $now = time();
        $header = 't=' . $now . ',v1=' . hash_hmac('sha256', $now . '.' . $body, self::SECRET);
        $event = Webhooks::constructEvent($body, $header, self::SECRET);
        $this->assertSame('crypto_payment.matched', $event['event']);
        $this->assertSame('matched', $event['status']);
        $this->assertTrue($event['overpaid']);
        $this->assertSame($event['amount'], $event['received_amount']);
    }

    public function testLegacyOverpaidStringsNeverCrashTheParser(): void
    {
        // An event or status string this SDK no longer knows (sent briefly before overpayments
        // within tolerance became "matched") parses fine; a handler simply acknowledges it.
        $body = json_encode([
            'event' => 'crypto_payment.overpaid',
            'id' => 'evt_4c9d2e7f0a1b4c83b6e5d9a2f7c1e087',
            'created' => 1790000600,
            'api_version' => '2026-10-15',
            'payment_request_id' => 'cpr_7ae03d5b1c9e4f2a8d6b0c3e1f4a7b92',
            'order_ref' => 'order_10231',
            'status' => 'overpaid',
            'asset_id' => 'polygon_usdc',
            'requested_amount' => '49.00',
            'expected_amount' => '49.0137',
            'received_amount' => '50.0137',
            'difference' => '1',
            'difference_pct' => '2.04',
            'match_method' => 'tolerance',
            'tx_hash' => '0xabc',
            'test_mode' => false,
            'metadata' => null,
            'description' => null,
        ]);
        $now = time();
        $header = 't=' . $now . ',v1=' . hash_hmac('sha256', $now . '.' . $body, self::SECRET);
        $event = Webhooks::constructEvent($body, $header, self::SECRET);
        $this->assertSame('crypto_payment.overpaid', $event['event']);
        $this->assertNotContains($event['event'], Webhooks::EVENT_TYPES);
        $this->assertNotContains($event['status'], CryptoPaymentRequests::PAID_STATUSES);
        $this->assertSame('50.0137', $event['received_amount']);
    }
}
