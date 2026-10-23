<?php

declare(strict_types=1);

namespace Adeptix\Exceptions;

use Exception;

/**
 * Thrown for any non-2xx response from the Adeptix API. getErrorCode() matches the `error` field
 * documented at https://docs.adeptix.app/errors - always check that, not getMessage() (which
 * isn't guaranteed to be present or stable across API versions).
 */
class AdeptixApiException extends Exception
{
    /** Every error code the API documents today. New codes may be added: compare, don't exhaustively match. */
    public const KNOWN_ERROR_CODES = [
        'unauthorized',
        'invalid_request',
        'not_found',
        'platform_not_configured',
        'internal_error',
        'rate_limited',
        'asset_unavailable',
        'no_quote_available',
        'too_many_pending_requests',
        'invalid_expiry',
        'amount_below_minimum',
        'provider_unavailable',
        'provider_disabled_for_account',
        'checkout_provider_error',
        'idempotency_key_reuse',
        'idempotent_request_in_progress',
    ];

    private int $statusCode;
    private string $errorCode;
    /** @var mixed */
    private $details;
    private ?string $minimumAmount;

    /**
     * @param mixed $details
     * @param string|null $minimumAmount The asset's minimum, sent with `amount_below_minimum`.
     */
    public function __construct(int $statusCode, string $errorCode, ?string $message, $details = null, ?string $minimumAmount = null)
    {
        parent::__construct($message ?? "Adeptix API request failed with {$statusCode} ({$errorCode})");
        $this->statusCode = $statusCode;
        $this->errorCode = $errorCode;
        $this->details = $details;
        $this->minimumAmount = $minimumAmount;
    }

    /** The asset's minimum amount (decimal string) when getErrorCode() is `amount_below_minimum`, else null. */
    public function getMinimumAmount(): ?string
    {
        return $this->minimumAmount;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /** @return mixed */
    public function getDetails()
    {
        return $this->details;
    }
}
