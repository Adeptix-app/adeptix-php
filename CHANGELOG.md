# Changelog

## 0.3.0

All additions; existing code keeps working unchanged (the plugins that bundle this SDK are unaffected).

- **Payment window**: `crypto()->createPaymentRequest()` accepts an optional `expires_in_minutes`
  (10-60, default 20). New error code `invalid_expiry`.
- **Minimum amount**: requests below the asset's `min_amount` are refused with the new error code
  `amount_below_minimum`; `AdeptixApiException::getMinimumAmount()` returns the minimum.
  `AdeptixApiException::KNOWN_ERROR_CODES` lists the documented codes.
- **Richer status**: the status array gains `asset_id`, `chain`, `token`, `pay_to_address` and
  `payment` (`tx_hash`, `amount`, `block_number`, `detected_at`, `credited_amount`,
  `commission_amount`; null until matched).
- **List**: `crypto()->paymentRequests()->list(['status' => ..., 'order_ref' => ..., 'created_after' =>
  ..., 'limit' => ...])` (newest first, limit 1-100, default 25) and `->get($id)`.
- **Webhooks**: optional `manual: true` on `crypto_payment.matched` when support assigned a payment
  by hand after review.
- **Tolerance matching**: a slightly different amount that can only belong to one request is
  matched and you are credited what arrived. Slightly **more** counts as paid: status `matched` and
  `crypto_payment.matched` with the new optional keys `overpaid` (true), `expected_amount`,
  `received_amount`, `difference`, `difference_pct` and `match_method` (the extra is not refunded
  automatically). Slightly **less**: the request status `underpaid` and the webhook event
  `crypto_payment.underpaid` (`requested_amount`, `expected_amount`, `received_amount`, `difference`,
  `difference_pct`, `match_method`, `tx_hash`): fulfil only if you accept the amount received. Also
  `payment.match_method` and the new constants `CryptoPaymentRequests::STATUSES`,
  `CryptoPaymentRequests::PAID_STATUSES` and `Webhooks::EVENT_TYPES`.

## 0.2.0

All additions; existing code keeps working unchanged (the plugins that bundle this SDK are unaffected).

- **Idempotency**: `payments()->create()` and `crypto()->createPaymentRequest()` accept an optional
  `idempotency_key`; `AdeptixClient::request()` takes it as an optional 4th argument. Error codes
  `idempotency_key_reuse` and `idempotent_request_in_progress` surface via `getErrorCode()`.
- **Checkout fields**: `description`, `return_url`, `cancel_url` and `metadata` on both create calls;
  echoed back on create and status responses. Crypto create/status responses now include
  `checkout_url`, a hosted payment page.
- **Discovery**: `providers()->list()`, `providers()->get($id)` and `crypto()->assets()->list()`.
- **Webhooks v2**: `Webhooks::constructEvent()` and `Webhooks::verifySignatureV2()` verify the
  `Adeptix-Signature` header (timestamped, replay-protected, default tolerance 300 s). New events
  `payment.failed` and `crypto_payment.expired`; every event carries `id`, `created` and
  `api_version` plus `metadata` and `description`. `verifySignature()` and `parseEvent()` are unchanged.
- Handle unknown `event` values by acknowledging them.

## 0.1.x

Earlier releases.
