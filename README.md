# Plorea SDK for Laravel

A Laravel SDK for the [Plorea Payments API](https://docs.plorea.no) — payment
links, refunds, stored payment methods, subscriptions and webhooks, with an
expressive, fluent API and first-class testing support.

Plorea abstracts the underlying payment provider (Adyen) behind a simple API:
you create a payment link, your customer pays on `pay.plorea.no`, and Plorea
notifies you via webhook.

```php
use MemberFlow\Plorea\Data\Amount;
use MemberFlow\Plorea\Facades\Plorea;

$link = Plorea::payments()
    ->link('FIN-2026-00123', 'Faktura FIN-2026-00123', Amount::nok(450000), 'https://app.example/paid')
    ->payerEmail('kunde@eksempel.no')
    ->create();

return redirect($link->url);
```

## Requirements

- PHP 8.4+
- Laravel 12 or 13

## Installation

```bash
composer require memberflow/plorea
```

Publish the config file if you need to change more than the environment
variables cover:

```bash
php artisan vendor:publish --tag=plorea-config
```

## Configuration

Set your credentials in `.env`:

```dotenv
PLOREA_API_KEY=plr_test_...
PLOREA_ENVIRONMENT=test        # test | live
PLOREA_TENANT_ID=your-tenant
PLOREA_WEBHOOK_SECRET=         # optional, enables signature verification
```

| Env var | Default | Purpose |
| --- | --- | --- |
| `PLOREA_API_KEY` | — | Bearer token for the Plorea API |
| `PLOREA_ENVIRONMENT` | `test` | Sent as the `X-Environment` header on every request |
| `PLOREA_BASE_URL` | `https://payments.plorea.no` | API base URL |
| `PLOREA_TENANT_ID` | — | Your tenant, injected into every request that needs one |
| `PLOREA_WEBHOOK_SECRET` | — | HMAC secret for verifying incoming webhooks |

All amounts are in **minor units** (øre): `Amount::nok(450000)` is 4 500,00 kr.

## Payments

### Create a payment link

`link()` takes the required fields; everything optional is chained:

```php
$link = Plorea::payments()
    ->link(
        reference: 'FIN-2026-00123',          // your unique reference
        product: 'Faktura FIN-2026-00123',
        amount: Amount::nok(450000),
        returnUrl: 'https://app.example/paid',
    )
    ->payerEmail('kunde@eksempel.no')
    ->invoiceUrl('https://app.example/invoices/123.pdf')
    // Providing merchant details triggers KYC onboarding and splits:
    ->merchant(orgNr: '912650774', name: 'Techify AS', email: 'post@techify.no')
    ->create();

$link->url;       // https://pay.plorea.no/...  — send your customer here
$link->id;        // pl_...
$link->expiresAt; // CarbonImmutable|null
```

### Check payment status

```php
$status = Plorea::payments()->status('FIN-2026-00123');

$status->isAuthorised(); // bool
$status->status;         // authorised, refused, refund_requested, ...
$status->amount;         // Amount|null
$status->pspReference;
```

### Refund or cancel

```php
Plorea::payments()->refund(
    'FIN-2026-00123',
    modificationReference: 'FIN-2026-00123-refund-1', // your idempotency reference
    amount: Amount::nok(450000),                      // omit for a full refund
    reason: 'Customer requested refund',
);

Plorea::payments()->cancel('FIN-2026-00123', 'FIN-2026-00123-cancel-1');
```

## Payment methods

Store a card for recurring charges. Two flows are supported:

```php
use MemberFlow\Plorea\Enums\RecurringType;

// Hosted: redirect the customer to an Adyen-hosted page
$method = Plorea::paymentMethods()
    ->setup('customer-123', RecurringType::Subscription, 'https://app.example/return')
    ->create();

return redirect($method->adyenPaymentLinkUrl);

// Drop-in: embed the Adyen Drop-in in your own UI
$session = Plorea::paymentMethods()
    ->setup('customer-123', RecurringType::Subscription, 'https://app.example/return')
    ->session();

$session->sessionId;
$session->sessionData;
```

Poll until the customer has completed the setup:

```php
$method = Plorea::paymentMethods()->find($method->id);

$method->isActive();  // card stored, ready to charge
$method->cardLast4;   // "0004"
$method->cardBrand;   // "mc"
```

## Subscriptions

### Create

```php
use MemberFlow\Plorea\Data\{Amount, BillingInterval};

$subscription = Plorea::subscriptions()
    ->create('pm_63cd...', Amount::nok(19900), BillingInterval::monthly())
    ->externalId('ws_acme_456')           // link to an entity in your system
    ->title('Done CRM Pro')
    ->quantity(5)
    ->vat(rate: 0.25, amount: 3980)
    ->trialUntil(now()->addDays(14))
    ->retryPolicy(3, retryIntervalDays: 2)
    ->save();

$subscription->isActive();
$subscription->nextChargeAt;
```

### Update, cancel, reactivate

```php
Plorea::subscriptions()->update($subscription->id)
    ->amount(Amount::nok(39900))
    ->quantity(10)
    ->save();

Plorea::subscriptions()->cancel($subscription->id, 'customer_requested');

// After the customer fixes their card on a payment_failed subscription:
Plorea::subscriptions()->update($subscription->id)->paymentMethod('pm_new...')->save();
Plorea::subscriptions()->reactivate($subscription->id);
```

### Find and list

```php
$subscription = Plorea::subscriptions()->find('sub_774c...');

// All subscriptions for one of your entities:
$subscriptions = Plorea::subscriptions()->forExternalId('ws_acme_456', status: 'active');
```

### Charges

```php
// Manual, off-schedule charge (throws ChargeFailedException when declined):
$charge = Plorea::subscriptions()->charge($subscription->id, reason: 'extra_seat');

// Charge history, newest first:
$charges = Plorea::subscriptions()->charges($subscription->id);
```

## Webhooks

The package registers `POST /plorea/webhook` automatically. Point your Plorea
webhook there and listen for the events:

```php
use MemberFlow\Plorea\Events\{PaymentStatusUpdated, WebhookReceived};

Event::listen(PaymentStatusUpdated::class, function (PaymentStatusUpdated $event) {
    $event->reference; // your payment reference
    $event->status;    // e.g. authorised
    $event->payload;   // the full webhook payload
});

// Or the raw payload for every webhook:
Event::listen(WebhookReceived::class, fn (WebhookReceived $event) => $event->payload);
```

Configure the path, middleware, or disable the route entirely in
`config/plorea.php`. When `PLOREA_WEBHOOK_SECRET` is set, the
`X-Plorea-Signature` header is verified as a hex-encoded HMAC-SHA256 digest of
the raw request body, and requests with a missing or invalid signature are
rejected with a 403.

Remember to exclude the webhook path from CSRF verification if your
application applies it globally:

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->validateCsrfTokens(except: ['plorea/webhook']);
})
```

## Testing

Swap the HTTP client for a fake — no requests leave your test suite, and every
endpoint has a sensible default response:

```php
use MemberFlow\Plorea\Facades\Plorea;

Plorea::fake();

$link = Plorea::payments()
    ->link('ref-1', 'Product', Amount::nok(50000), 'https://example.test/return')
    ->create();

Plorea::assertSent('payments/link');
Plorea::assertSent(fn ($request) => $request->input('amount') === 50000);
Plorea::assertSentCount(1);
```

Stub specific endpoints with arrays, callables, or exceptions:

```php
use MemberFlow\Plorea\Exceptions\ChargeFailedException;

Plorea::fake([
    'payments/status/*' => ['reference' => 'ref-1', 'status' => 'refused'],
    'POST payments/link' => fn ($request) => ['paymentLinkId' => 'pl_1', /* ... */],
    'subscriptions/*/charge' => new ChargeFailedException('Charge failed: Refused', 402),
]);
```

Patterns match the request path (`payments/*`), optionally prefixed with a
method (`POST payments/link`).

## Error handling

All exceptions extend `MemberFlow\Plorea\Exceptions\PloreaException`:

| Exception | Thrown when |
| --- | --- |
| `ValidationException` | 400 — invalid request data |
| `AuthenticationException` | 401 / 403 — invalid or missing API key |
| `ChargeFailedException` | 402 — a subscription charge was declined |
| `NotFoundException` | 404 — unknown reference or ID |
| `ServerException` | 5xx — Plorea-side error |
| `ConnectionException` | The API could not be reached |

`RequestException` (the parent of the HTTP errors above) exposes the response:

```php
try {
    Plorea::subscriptions()->charge($id);
} catch (ChargeFailedException $e) {
    $e->status;              // 402
    $e->response?->json();   // the raw error body
}
```

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
