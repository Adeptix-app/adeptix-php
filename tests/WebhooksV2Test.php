<?php

declare(strict_types=1);

namespace Adeptix\Tests;

use Adeptix\Webhooks;
use PHPUnit\Framework\TestCase;

// Webhooks v2: `Adeptix-Signature: t=<unix seconds>,v1=<hex HMAC-SHA256 of "<t>.<raw body>">`.
final class WebhooksV2Test extends TestCase
{
    private const SECRET = 'whsec_test_secret';
    private const NOW = 1_800_000_000;

    private function signV2(string $body, string $secret, int $ts): string
    {
        return 't=' . $ts . ',v1=' . hash_hmac('sha256', $ts . '.' . $body, $secret);
    }

    public function testMatchesTheCrossSdkTestVector(): void
    {
        // The same vector the Node and Python SDK tests use: all three must agree on it.
        $body = '{"event":"payment.paid","id":"evt_1"}';
        $header = 't=1800000000,v1=eef418a69de72d5244d7bd44535f9c515ed4fa7cbb9f241319d4f372078c37b7';
        $this->assertTrue(Webhooks::verifySignatureV2($body, $header, self::SECRET, 300, self::NOW));
    }

    public function testAcceptsAValidSignatureInsideTheToleranceWindow(): void
    {
        $body = json_encode(['event' => 'payment.paid', 'id' => 'evt_1']);
        $header = $this->signV2($body, self::SECRET, self::NOW);
        $this->assertTrue(Webhooks::verifySignatureV2($body, $header, self::SECRET, 300, self::NOW));
        $this->assertTrue(Webhooks::verifySignatureV2($body, $header, self::SECRET, 300, self::NOW + 300));
        $this->assertTrue(Webhooks::verifySignatureV2($body, $header, self::SECRET, 300, self::NOW - 300));
    }

    public function testRejectsStaleOrFutureTimestampsAndToleranceIsConfigurable(): void
    {
        $header = $this->signV2('{}', self::SECRET, self::NOW);
        $this->assertFalse(Webhooks::verifySignatureV2('{}', $header, self::SECRET, 300, self::NOW + 301));
        $this->assertFalse(Webhooks::verifySignatureV2('{}', $header, self::SECRET, 300, self::NOW - 301));
        $this->assertTrue(Webhooks::verifySignatureV2('{}', $header, self::SECRET, 900, self::NOW + 600));
    }

    public function testRejectsATamperedBodyWrongSecretAndRestampedTimestamp(): void
    {
        $body = json_encode(['event' => 'payment.paid']);
        $header = $this->signV2($body, self::SECRET, self::NOW);
        $this->assertFalse(Webhooks::verifySignatureV2($body . ' ', $header, self::SECRET, 300, self::NOW));
        $this->assertFalse(Webhooks::verifySignatureV2($body, $header, 'other', 300, self::NOW));
        $restamped = str_replace('t=' . self::NOW, 't=' . (self::NOW + 1), $header);
        $this->assertFalse(Webhooks::verifySignatureV2($body, $restamped, self::SECRET, 300, self::NOW));
    }

    public function testALegacySignatureIsNotAV2SignatureAndViceVersa(): void
    {
        $body = '{}';
        $legacy = 'sha256=' . hash_hmac('sha256', $body, self::SECRET);
        $this->assertFalse(Webhooks::verifySignatureV2($body, $legacy, self::SECRET, 300, self::NOW));
        $this->assertFalse(Webhooks::verifySignature($body, $this->signV2($body, self::SECRET, self::NOW), self::SECRET));
        $this->assertTrue(Webhooks::verifySignature($body, $legacy, self::SECRET)); // legacy verifier unchanged
    }

    public function testAcceptsAnyMatchingV1AndIgnoresUnknownKeysAndNeverThrowsOnGarbage(): void
    {
        $good = explode('v1=', $this->signV2('{}', self::SECRET, self::NOW))[1];
        $zeros = str_repeat('0', 64);
        $this->assertTrue(Webhooks::verifySignatureV2('{}', 't=' . self::NOW . ',v1=' . $zeros . ',v1=' . $good, self::SECRET, 300, self::NOW));
        $this->assertTrue(Webhooks::verifySignatureV2('{}', 'v0=x, t=' . self::NOW . ' , v1=' . $good . ', future=1', self::SECRET, 300, self::NOW));

        $garbage = [null, '', 'garbage', 't=,v1=', 't=abc,v1=' . $good, 'v1=' . $good, 't=' . self::NOW, 't=' . self::NOW . ',t=' . self::NOW . ',v1=' . $good, 't=' . self::NOW . ',v1=zz'];
        foreach ($garbage as $header) {
            $this->assertFalse(Webhooks::verifySignatureV2('{}', $header, self::SECRET, 300, self::NOW), (string) $header);
        }
    }

    public function testConstructEventReturnsTheEventForEachKnownType(): void
    {
        foreach (['payment.paid', 'payment.failed', 'crypto_payment.matched', 'crypto_payment.expired'] as $name) {
            $body = json_encode(['event' => $name, 'id' => 'evt_1', 'created' => self::NOW, 'api_version' => '2026-10-15']);
            $event = Webhooks::constructEvent($body, $this->signV2($body, self::SECRET, time()), self::SECRET);
            $this->assertSame($name, $event['event']);
            $this->assertSame('evt_1', $event['id']);
        }
    }

    public function testConstructEventThrowsOnBadOrStaleSignatures(): void
    {
        $body = json_encode(['event' => 'payment.paid']);
        foreach (['t=1,v1=' . str_repeat('0', 64), $this->signV2($body, self::SECRET, 1000), null] as $header) {
            try {
                Webhooks::constructEvent($body, $header, self::SECRET);
                $this->fail('Expected an exception');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('verification failed', $e->getMessage());
            }
        }
    }
}
