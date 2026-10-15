# Changelog

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
