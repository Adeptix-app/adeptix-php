# adeptix/adeptix-php

Official PHP SDK for the [Adeptix API](https://docs.adeptix.app) — direct on-chain crypto
payments and hosted card/bank/wallet checkouts, from a single client.

Full API reference: **https://docs.adeptix.app**

## Install

This SDK installs straight from GitHub. Add the repository to your project's `composer.json`:

```json
"repositories": [{ "type": "vcs", "url": "https://github.com/Adeptix-app/adeptix-php" }]
```

then require the release series:

```bash
composer require adeptix/adeptix-php:^0.2
```

(Use `adeptix/adeptix-php:dev-main` instead to track the latest commit on `main`.)

Requires PHP 8.0+ with the `curl` and `json` extensions (bundled with virtually every PHP
install). This is also the foundation the official WooCommerce/WHMCS/PrestaShop/OpenCart plugins
build on — install it directly only if you're integrating custom PHP code.

## Quickstart

```php
use Adeptix\AdeptixClient;

$client = new AdeptixClient(getenv('ADEPTIX_API_KEY'));
```

### Crypto Payments API

```php
$request = $client->crypto()->createPaymentRequest([
    'chain' => 'polygon',
    'token' => 'USDC',
    'amount' => '49.00',
    'order_ref' => 'order_10231',
]);

// Show $request['amount'] and $request['pay_to_address'] to the customer - or send them to
// $request['checkout_url'], a hosted payment page (amount, address, QR code, countdown, live status).
// $request['amount'] may differ slightly from "49.00" - see the docs' note on how deposits are
// matched by exact amount, not by a per-request address.

$status = $client->crypto()->getPaymentRequest($request['payment_request_id']);
// $status['status']: "pending" | "matched" | "expired"
```

### Merchant Payments API

```php
$payment = $client->payments()->create([
    'amount' => '49.00',
    'currency' => 'USD',
    'email' => 'customer@example.com',
    'provider' => 'stripe',
    'order_ref' => 'order_10231',
]);

// Redirect the customer's browser to $payment['payment_url'].

$status = $client->payments()->get($payment['transaction_id']);
// $status['status']: "pending" | "paid" | "failed" | "expired"
```

### Optional fields: description, return URL, cancel URL, metadata

Both create calls accept `description`, `return_url`, `cancel_url` and `metadata`; they are echoed back
in responses and (`metadata`, `description`) in webhooks.

```php
$client->crypto()->createPaymentRequest([
    'chain' => 'tron',
    'token' => 'USDT',
    'amount' => '49.00',
    'description' => 'Annual plan',
    'return_url' => 'https://shop.example.com/thanks', // used by the hosted checkout page
    'cancel_url' => 'https://shop.example.com/cart',
    'metadata' => ['cart_id' => 'c_8841'], // up to 20 string keys / string values
]);
```

> `return_url` / `cancel_url` are applied on Adeptix-hosted pages (the crypto `checkout_url` page and
> the test-mode sandbox page). A customer paying by card or wallet through a live
> `payments()->create()` checkout is **not** redirected back automatically - confirm the payment with
> the webhook or `payments()->get()`, not by waiting for the customer to arrive at your return URL.

### Idempotency

Pass `idempotency_key` to a create call and a retry of that same call can never create a second
payment: the same key with the same request returns the original response (same status and body); the
same key with a different request throws `AdeptixApiException` with error code `idempotency_key_reuse`;
the same key while the first request is still running throws `idempotent_request_in_progress` (HTTP 409).

```php
$idempotencyKey = bin2hex(random_bytes(16)); // once per logical payment - store it with your order
$payment = $client->payments()->create([
    'amount' => '49.00',
    'currency' => 'USD',
    'email' => 'customer@example.com',
    'provider' => 'stripe',
    'idempotency_key' => $idempotencyKey,
]);
// On a timeout, call it again with the SAME idempotency_key.
```

Keys are 1-255 printable ASCII characters and are remembered for 24 hours. See
**https://docs.adeptix.app/idempotency**.

### Discovery

```php
$providers = $client->providers()->list(); // ids you can pass as 'provider'
$stripe = $client->providers()->get('stripe');
$assets = $client->crypto()->assets()->list(); // chain/token pairs you can request
```

## Webhooks

Prefer webhooks over polling — see **https://docs.adeptix.app/webhooks** for how to configure a
webhook URL and secret from your dashboard.

```php
use Adeptix\Webhooks;

// IMPORTANT: verification needs the raw, unparsed body.
$rawBody = file_get_contents('php://input');
$signature = $_SERVER['HTTP_ADEPTIX_SIGNATURE'] ?? null;

try {
    // Verifies the Adeptix-Signature header (HMAC over "<timestamp>.<raw body>") and rejects
    // timestamps more than 300 seconds off, so a captured delivery can't be replayed later.
    $event = Webhooks::constructEvent($rawBody, $signature, getenv('ADEPTIX_WEBHOOK_SECRET'));
} catch (\RuntimeException $e) {
    http_response_code(401);
    exit;
}

if (alreadyProcessed($event['id'])) { // dedupe on the event id
    http_response_code(200);
    exit;
}

if (!empty($event['test_mode'])) {
    // A test-key payment - acknowledge it, but never fulfil a real order from it.
    http_response_code(200);
    exit;
}

if ($event['event'] === 'payment.paid') {
    // mark your order paid - $event['order_ref'] / $event['transaction_id']
} elseif ($event['event'] === 'crypto_payment.matched') {
    // mark your order paid - $event['order_ref'] / $event['payment_request_id']
} elseif ($event['event'] === 'payment.failed' || $event['event'] === 'crypto_payment.expired') {
    // a (test) card payment failed / a crypto request timed out unpaid
}
// Any other event type: new ones can be added - acknowledge and ignore.

markProcessed($event['id']);
http_response_code(200);
```

Every event has a stable `id` (identical on retries), `created` (unix seconds) and `api_version`.
`Webhooks::verifySignatureV2($rawBody, $header, $secret)` returns a `bool` and never throws.

`constructEvent` / `verifySignatureV2` read the newer `Adeptix-Signature` header. The original
`parseEvent` / `verifySignature` (which read `x-adeptix-signature`) still work unchanged if you haven't
moved over - both headers are sent on every delivery. See **https://docs.adeptix.app/webhooks**.

Handlers should be idempotent — the same event can be delivered more than once.

## Test mode

Create a **Test** key (`ak_test_...`) from the dashboard's API Keys page and pass it to
`AdeptixClient` — nothing else in the client changes. No real payment is made and nothing touches a
blockchain; the amount you send picks the outcome:

- Merchant payments: an amount ending in `.00` is `paid` immediately (and a `payment.paid` webhook is
  sent), `.01` is `failed` (and a `payment.failed` webhook is sent), anything else stays `pending`.
- Crypto payments: a requested amount ending in `.00` is `matched` immediately (and a
  `crypto_payment.matched` webhook is sent); anything else stays `pending` until it expires, then a
  `crypto_payment.expired` webhook is sent.
  `pay_to_address` is the literal string `"sandbox"`.

Test webhooks go to the same URL as live ones with `"test_mode": true` — make your production handler
ignore them, as in the example above. See **https://docs.adeptix.app** for details.

## Error handling

Every non-2xx response throws `Adeptix\Exceptions\AdeptixApiException`. Always check
`getErrorCode()`, not `getMessage()` (which isn't guaranteed to be present or stable):

```php
use Adeptix\Exceptions\AdeptixApiException;

try {
    $client->payments()->create(['amount' => '49.00', 'currency' => 'USD', 'email' => 'a@b.com', 'provider' => 'stripe']);
} catch (AdeptixApiException $e) {
    error_log("{$e->getStatusCode()} {$e->getErrorCode()}");
}
```

See **https://docs.adeptix.app/errors** for the full list of error codes.

## Retries

`GET` requests (status checks) are automatically retried on a network error or a 5xx response,
with exponential backoff (2 retries by default). `POST` requests are **never** auto-retried by the
client - without an idempotency key a retried `POST` could create a second payment request or checkout
session. Retry them yourself with an `idempotency_key` (see above) and that is safe. Configure with the
constructor's 4th argument:

```php
$client = new AdeptixClient(
    apiKey: getenv('ADEPTIX_API_KEY'),
    maxRetries: 3,
    timeoutSeconds: 20
);
```

## Testing this SDK itself

```bash
composer install
composer test
```

## License

MIT
