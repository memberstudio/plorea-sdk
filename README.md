# Plorea SDK for Laravel

[![Tests](https://github.com/memberstudio/plorea-sdk/actions/workflows/tests.yml/badge.svg)](https://github.com/memberstudio/plorea-sdk/actions/workflows/tests.yml)
[![Static Analysis](https://github.com/memberstudio/plorea-sdk/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/memberstudio/plorea-sdk/actions/workflows/static-analysis.yml)
[![Latest Stable Version](https://img.shields.io/packagist/v/memberflow/plorea)](https://packagist.org/packages/memberflow/plorea)
[![License](https://img.shields.io/packagist/l/memberflow/plorea)](LICENSE.md)

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

- [Installation](#installation)
- [Configuration](#configuration)
- [Payments](#payments) — [create](#create-a-payment-link) · [firstOrCreate](#reuse-or-create-firstorcreate) · [status](#check-payment-status) · [refund / cancel](#refund-or-cancel)
- [Payment methods](#payment-methods)
- [Subscriptions](#subscriptions)
- [Webhooks](#webhooks)
- [Testing](#testing)
- [Error handling](#error-handling)

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
PLOREA_WEBHOOK_SECRET=         # required for webhooks — the route rejects requests until it is set
```

| Env var | Default | Purpose |
| --- | --- | --- |
| `PLOREA_API_KEY` | — | Bearer token for the Plorea API |
| `PLOREA_ENVIRONMENT` | `test` | Sent as the `X-Environment` header on every request |
| `PLOREA_BASE_URL` | `https://payments.plorea.no` | API base URL |
| `PLOREA_TENANT_ID` | — | Your tenant, injected into every request that needs one |
| `PLOREA_WEBHOOK_SECRET` | — | Shared secret for authenticating incoming webhooks (required for the webhook route to accept requests) |

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

KYC is implicit: the first link for a new `merchantOrgNr` starts merchant
onboarding, and `merchantEmail` receives the KYC mail. The link works while
KYC is pending — the payout is simply held in escrow until onboarding
completes (typically 1–5 days).

### Reuse or create (`firstOrCreate`)

Plorea has no idempotency on duplicate references — creating twice gives you
two live links. `firstOrCreate()` checks the stored payment state first:

```php
$link = Plorea::payments()
    ->link('FIN-2026-00123', 'Faktura FIN-2026-00123', Amount::nok(450000), 'https://app.example/paid')
    ->firstOrCreate();
```

- An **open** link with the same amount, currency, tenant, and environment
  is returned as-is.
  Because the status endpoint exposes no expiry (and the status string has
  not been observed to flip to `expired`), the candidate link is verified
  against the pay-page endpoint, which reports a computed `expired` flag —
  an open-but-expired link is superseded instead of handed out again.
- A **dead** link (expired, cancelled, refunded) or an open link with a
  different amount is superseded by a new link with a suffixed reference
  (`FIN-2026-00123-1`, `-2`, ...).
- An already **paid** reference throws `PaymentAlreadyPaidException` — a
  fresh link for a settled invoice would be payable again, so this fails
  loudly instead. The exception carries the `PaymentStatus`.

Two caveats: the check-then-create is not atomic, so two calls racing on the
same new reference can still both create a link — serialize concurrent calls
per reference (e.g. `Cache::lock()`) if double submits are possible. And the
suffix scheme assumes `{reference}-1` is not itself a real, distinct invoice
in your numbering.

### Check payment status

```php
$status = Plorea::payments()->status('FIN-2026-00123');

$status->isPaid();             // authorised or paid — money moved
$status->isOpen();             // created, pending or active — still payable
$status->isRefundRequested();  // a refund request was accepted, provider settling
$status->isCancelRequested();  // a cancel request was accepted, provider settling
$status->status;               // the raw status string
$status->amount;               // Amount|null
$status->pspReference;
```

Observed statuses: `created`, `pending`, `active` (open), `authorised`,
`paid` (paid — test payments settle on `authorised`), `refund_requested`,
`cancel_requested` (a modification was accepted and awaits the provider),
`cancelled`,
`refunded`. `expired` is handled defensively but has not been observed live —
links past their expiry keep reporting an open status, so judge expiry from
the `expiresAt` you stored when creating the link (or the pay-page endpoint),
not the status string. The model is deliberately open: unknown strings pass
through on `status` and can be checked with `is('...')`.

The `webhookEventCode` / `webhookSuccess` / `lastWebhookAt` fields describe
the inbound Adyen webhook Plorea received for the payment — not the webhook
Plorea sends to your application.

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

Both requests return immediately with a `refund_requested` /
`cancel_requested` status — the provider settles the modification
asynchronously, so poll `status()` (or wait for a webhook) to observe the
final state.

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

Webhook registration with Plorea is **manual, per tenant**: hand Plorea your
webhook URL via a secure channel and obtain the signing secret from them.
Real deliveries (captured from Plorea's test environment) are **signed, not
authenticated with an echoed header**: each request carries an
`X-Plorea-Signature` header holding a base64-encoded HMAC-SHA256 of the raw
request body, plus `x-plorea-event-id` (`evt_…`) and `x-plorea-event`
(e.g. `payment.authorised`) headers. The middleware verifies the signature
with `PLOREA_WEBHOOK_SECRET`; requests without a signature header fall back
to comparing the `Authorization` header against the secret verbatim
(optionally `Bearer `-prefixed), for registrations that use an echoed
shared secret instead.

The package registers `POST /plorea/webhook` automatically and rejects every
request until `PLOREA_WEBHOOK_SECRET` is configured (it fails closed). With
the secret in place, listen for the events:

> [!NOTE]
> Captured deliveries look like `{eventId, createdAt, tenantId, type,
> data: {reference, status, eventCode, success, …}}` — see
> `tests/Fixtures/webhook-payment-authorised.json` for a full example. The
> package extracts the reference and status defensively (nested `data.*`
> first-party keys plus flat fallbacks) but still treats the payload as
> untrusted — which is exactly why the listener below re-fetches the
> authoritative state instead of reading it from the payload.

```php
use MemberFlow\Plorea\Events\{PaymentStatusUpdated, WebhookReceived};

Event::listen(PaymentStatusUpdated::class, function (PaymentStatusUpdated $event) {
    // Treat the webhook as a ping: fetch the authoritative state instead of
    // trusting status or amount from the payload.
    $status = Plorea::payments()->status($event->reference);

    if ($status->isPaid()) {
        // mark the invoice paid — idempotently: the webhook, a status poll,
        // and the customer's return page can all race on the same reference.
    }
});

// Or the raw payload for every webhook:
Event::listen(WebhookReceived::class, fn (WebhookReceived $event) => $event->payload);
```

Two practices worth copying from production integrations:

- **Verify amounts before booking.** Compare `$status->amount` against what
  you expected locally; on mismatch, flag for manual handling instead of
  auto-booking.
- **Poll as backup.** Webhook delivery is best-effort — run a scheduled job
  that calls `status()` for your open payment links.

Configure the path, extra middleware, or disable the route entirely in
`config/plorea.php`. Adding a throttle to the extra middleware (e.g.
`'throttle:60,1'`) rate-limits the endpoint against secret-guessing.
Unparseable payloads are acknowledged with a 200 (there
is nothing to retry); return a 500 from your listener only for transient
failures where you want Plorea to redeliver.

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

Status lookups mirror the real API: a reference the fake has seen a payment
link created for reports an open (`active`) status echoing that link — so
`firstOrCreate()` works out of the box — while an unknown reference throws
`NotFoundException`, exactly like a live 404. Stub `payments/status/*` to
simulate paid, refused, or any other state.

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
| `PaymentAlreadyPaidException` | `firstOrCreate()` found the reference already paid |

Plorea's error bodies are not consistently shaped, so the exception message
falls back through `error`, `message`, and the raw body. `RequestException`
(the parent of the HTTP errors above) always exposes the full response:

```php
try {
    Plorea::subscriptions()->charge($id);
} catch (ChargeFailedException $e) {
    $e->status;              // 402
    $e->response?->json();   // the raw error body
}
```

## Contributing

Contributions are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md) for the
development setup and the checks your pull request must pass.

## Security

If you discover a security vulnerability, please follow the process in
[SECURITY.md](SECURITY.md) instead of opening a public issue.

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
