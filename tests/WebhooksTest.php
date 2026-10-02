<?php

declare(strict_types=1);

namespace Adeptix\Tests;

use Adeptix\Webhooks;
use PHPUnit\Framework\TestCase;

final class WebhooksTest extends TestCase
{
    private const SECRET = 'whsec_test_secret';

    private function sign(string $body, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $body, $secret);
    }

    public function testAcceptsACorrectlySignedPayload(): void
    {
        $body = json_encode(['event' => 'payment.paid', 'transaction_id' => 'txn_1']);
        $signature = $this->sign($body, self::SECRET);
        $this->assertTrue(Webhooks::verifySignature($body, $signature, self::SECRET));
    }

    public function testRejectsATamperedPayload(): void
    {
        $body = json_encode(['event' => 'payment.paid', 'transaction_id' => 'txn_1']);
        $signature = $this->sign($body, self::SECRET);
        $tamperedBody = json_encode(['event' => 'payment.paid', 'transaction_id' => 'txn_2']);
        $this->assertFalse(Webhooks::verifySignature($tamperedBody, $signature, self::SECRET));
    }

    public function testRejectsASignatureMadeWithTheWrongSecret(): void
    {
        $body = json_encode(['event' => 'payment.paid', 'transaction_id' => 'txn_1']);
        $signature = $this->sign($body, 'wrong_secret');
        $this->assertFalse(Webhooks::verifySignature($body, $signature, self::SECRET));
    }

    public function testRejectsAMissingSignatureHeader(): void
    {
        $this->assertFalse(Webhooks::verifySignature('{}', null, self::SECRET));
        $this->assertFalse(Webhooks::verifySignature('{}', '', self::SECRET));
    }

    public function testParseEventReturnsTheParsedEventWhenTheSignatureIsValid(): void
    {
        $body = json_encode(['event' => 'crypto_payment.matched', 'payment_request_id' => 'cpr_1']);
        $signature = $this->sign($body, self::SECRET);
        $parsed = Webhooks::parseEvent($body, $signature, self::SECRET);
        $this->assertSame('crypto_payment.matched', $parsed['event']);
    }

    public function testParseEventThrowsWhenTheSignatureIsInvalid(): void
    {
        $body = json_encode(['event' => 'payment.paid']);
        $this->expectException(\RuntimeException::class);
        Webhooks::parseEvent($body, 'sha256=wrong', self::SECRET);
    }
}
