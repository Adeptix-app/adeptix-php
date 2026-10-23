<?php

declare(strict_types=1);

namespace Adeptix;

/**
 * Verifies and parses Adeptix webhook deliveries. See https://docs.adeptix.app/webhooks
 */
class Webhooks
{
    /**
     * Verifies the `x-adeptix-signature` header against the raw (unparsed) request body using
     * your webhook secret. Always call this BEFORE json_decode-ing the body - signing covers the
     * exact bytes received, not whatever your JSON parser normalizes them to.
     */
    public static function verifySignature(string $rawBody, ?string $signatureHeader, string $webhookSecret): bool
    {
        if ($signatureHeader === null || $signatureHeader === '') {
            return false;
        }

        $provided = preg_replace('/^sha256=/', '', $signatureHeader);
        $expected = hash_hmac('sha256', $rawBody, $webhookSecret);

        return hash_equals($expected, (string) $provided);
    }

    /** How far (seconds) a v2 signature timestamp may be from your clock. */
    public const DEFAULT_TOLERANCE_SECONDS = 300;

    /**
     * Every event type this SDK version knows. crypto_payment.underpaid is sent instead of
     * crypto_payment.matched when slightly LESS than requested was matched: fulfil only if you accept
     * `received_amount`. Slightly MORE counts as paid: crypto_payment.matched with `overpaid` => true
     * plus `expected_amount`, `received_amount`, `difference`, `difference_pct` and `match_method`.
     * New types can be added: acknowledge the ones you don't handle.
     */
    public const EVENT_TYPES = [
        'payment.paid',
        'payment.failed',
        'crypto_payment.matched',
        'crypto_payment.underpaid',
        'crypto_payment.expired',
    ];

    /**
     * Verifies the `Adeptix-Signature` header (`t=<unix seconds>,v1=<hex>`): an HMAC-SHA256 over
     * "<t>.<raw body>", with replay protection. Returns false (never throws) for a missing or
     * malformed header, a timestamp more than $toleranceSeconds from now, or a signature that matches
     * none of the `v1` values. Call it BEFORE json_decode-ing the body.
     *
     * This is the recommended scheme; verifySignature() (the `x-adeptix-signature` header) keeps
     * working unchanged.
     *
     * @param int|null $now Current time in unix seconds; defaults to time() (for tests).
     */
    public static function verifySignatureV2(
        string $rawBody,
        ?string $signatureHeader,
        string $webhookSecret,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
        ?int $now = null
    ): bool {
        if ($signatureHeader === null || $signatureHeader === '') {
            return false;
        }

        $timestamp = null;
        $candidates = [];
        foreach (explode(',', $signatureHeader) as $part) {
            $kv = explode('=', $part, 2);
            if (count($kv) !== 2) {
                continue;
            }
            $key = trim($kv[0]);
            $value = trim($kv[1]);
            if ($key === 't') {
                if ($timestamp !== null || preg_match('/^[0-9]{1,12}$/', $value) !== 1) {
                    return false;
                }
                $timestamp = $value;
            } elseif ($key === 'v1' && preg_match('/^[0-9a-fA-F]{64}$/', $value) === 1) {
                $candidates[] = strtolower($value);
            }
        }
        if ($timestamp === null || $candidates === []) {
            return false;
        }

        if (abs(($now ?? time()) - (int) $timestamp) > $toleranceSeconds) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $webhookSecret);
        $ok = false;
        foreach ($candidates as $candidate) {
            $ok = hash_equals($expected, $candidate) || $ok;
        }
        return $ok;
    }

    /**
     * Verifies the `Adeptix-Signature` header (see verifySignatureV2) and decodes the body in one
     * step. Throws if verification fails (bad signature or a timestamp outside the tolerance window)
     * or the body isn't valid JSON - never returns an unverified event. Branch on `$event['event']`,
     * acknowledge (2xx) types you don't handle, and deduplicate on `$event['id']`.
     *
     * Known types: self::EVENT_TYPES (payment.paid, payment.failed, crypto_payment.matched,
     * crypto_payment.underpaid, crypto_payment.expired).
     *
     * @return array<string, mixed>
     */
    public static function constructEvent(
        string $rawBody,
        ?string $signatureHeader,
        string $webhookSecret,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS
    ): array {
        if (!self::verifySignatureV2($rawBody, $signatureHeader, $webhookSecret, $toleranceSeconds)) {
            throw new \RuntimeException('Adeptix webhook signature verification failed');
        }

        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Adeptix webhook payload was not valid JSON');
        }

        return $decoded;
    }

    /**
     * Verifies the signature and decodes the body in one step. Throws if the signature doesn't
     * match or the body isn't valid JSON - never returns an unverified event.
     *
     * Every event includes a boolean `test_mode` key: true when the payment was created with a test
     * key (ak_test_...). Test and live events go to the same webhook URL, so never fulfil a real
     * order from an event where `test_mode` is true.
     *
     * @return array<string, mixed>
     */
    public static function parseEvent(string $rawBody, ?string $signatureHeader, string $webhookSecret): array
    {
        if (!self::verifySignature($rawBody, $signatureHeader, $webhookSecret)) {
            throw new \RuntimeException('Adeptix webhook signature verification failed');
        }

        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Adeptix webhook payload was not valid JSON');
        }

        return $decoded;
    }
}
