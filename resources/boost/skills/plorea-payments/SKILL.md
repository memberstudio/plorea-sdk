---
name: plorea-payments
description: "Use this skill when working with the Plorea Payments SDK (memberflow/plorea) in a Laravel app. Trigger when creating or handling payment links, checking payment status, refunding or cancelling payments, storing cards / payment methods, creating or managing subscriptions, handling Plorea webhooks, or testing Plorea integrations with Plorea::fake(). Covers the Plorea facade, Amount minor units, firstOrCreate and PaymentAlreadyPaidException, merchant KYC onboarding, webhook signature verification, and the fake client's assertions and stubs."
license: MIT
metadata:
  author: memberflow
---
# Plorea Payments

All access goes through the `MemberFlow\Plorea\Facades\Plorea` facade. Amounts are **minor units** (`Amount::nok(450000)` = 4 500,00 kr).

## Payment links

```php
use MemberFlow\Plorea\Data\Amount;
use MemberFlow\Plorea\Facades\Plorea;

$link = Plorea::payments()
    ->link(
        reference: 'FIN-2026-00123',          // your unique reference
        product: 'Faktura FIN-2026-00123',
        amount: Amount::nok(450000),
        returnUrl: 'https://app.example/paid',
    )
    ->payerEmail('kunde@eksempel.no')
    ->invoiceUrl('https://app.example/invoices/123.pdf')
    // Required: the invoice issuer (the client) — never the platform's own org nr:
    ->merchant(orgNr: '912650774', name: 'Techify AS', email: 'post@techify.no')
    ->create();

$link->url;       // send the customer here
$link->id;        // pl_...
$link->expiresAt; // store this — expiry is judged from it, not from status
```

`merchantOrgNr` is required — `create()` throws `PloreaException` without it, because Plorea accepts the link but cannot route the payout. Name and email are optional but recommended (KYC communication). Never send a store or balance account; Plorea resolves those from the org number. The first payment for a new org number starts KYC automatically — the merchant receives an onboarding email, and the payout is released once KYC is approved (1–5 business days).

Prefer `->firstOrCreate()` over `->create()`: Plorea has no idempotency on duplicate references. It returns an existing open link as-is, supersedes dead or amount-changed links with a suffixed reference (`-1`, `-2`, ...), and throws `MemberFlow\Plorea\Exceptions\PaymentAlreadyPaidException` when the reference is already paid — catch it, never create a fresh link for a settled invoice. The check-then-create is not atomic; wrap in `Cache::lock()` per reference if double submits are possible.

## Status

```php
$status = Plorea::payments()->status('FIN-2026-00123');

$status->isPaid();             // authorised or paid — money moved
$status->isOpen();             // created, pending, active — still payable
$status->isRefundRequested();  // accepted, provider settling asynchronously
$status->isCancelRequested();
```

Use the helpers, not string comparison. `expired` never appears live — links past expiry keep reporting open; judge expiry from the stored `expiresAt`. The `webhookEventCode` / `webhookSuccess` / `lastWebhookAt` fields describe Plorea's inbound Adyen webhook, not webhooks sent to your app. Verify `$status->amount` against your local expectation before booking; on mismatch, flag for manual handling.

## Refund / cancel

```php
Plorea::payments()->refund('FIN-2026-00123', modificationReference: 'FIN-2026-00123-refund-1', amount: Amount::nok(450000), reason: '...');
Plorea::payments()->cancel('FIN-2026-00123', 'FIN-2026-00123-cancel-1');
```

Both return immediately with `refund_requested` / `cancel_requested`; the provider settles asynchronously — poll `status()` or wait for a webhook.

## Payment methods (stored cards)

```php
use MemberFlow\Plorea\Enums\RecurringType;

// Hosted page:
$method = Plorea::paymentMethods()->setup('customer-123', RecurringType::Subscription, 'https://app.example/return')->create();
return redirect($method->adyenPaymentLinkUrl);

// Or Adyen Drop-in session:
$session = Plorea::paymentMethods()->setup('customer-123', RecurringType::Subscription, 'https://app.example/return')->session();

// Poll until stored:
$method = Plorea::paymentMethods()->find($method->id);
$method->isActive();
```

## Subscriptions

```php
use MemberFlow\Plorea\Data\{Amount, BillingInterval};

$subscription = Plorea::subscriptions()
    ->create('pm_63cd...', Amount::nok(19900), BillingInterval::monthly())
    ->externalId('ws_acme_456')
    ->trialUntil(now()->addDays(14))
    ->retryPolicy(3, retryIntervalDays: 2)
    ->save();

Plorea::subscriptions()->update($subscription->id)->amount(Amount::nok(39900))->save();
Plorea::subscriptions()->cancel($subscription->id, 'customer_requested');
Plorea::subscriptions()->reactivate($subscription->id);
Plorea::subscriptions()->forExternalId('ws_acme_456', status: 'active');
Plorea::subscriptions()->charge($subscription->id, reason: 'extra_seat'); // throws ChargeFailedException when declined
```

## Webhooks

The package registers `POST /plorea/webhook` and verifies the `X-Plorea-Signature` header (base64 HMAC-SHA256 of the raw body) against `PLOREA_WEBHOOK_SECRET`. It fails closed without a secret; `PLOREA_WEBHOOK_VERIFY=false` is staging-only. Exclude `plorea/webhook` from CSRF verification.

Treat webhooks as pings — re-fetch the authoritative state:

```php
use MemberFlow\Plorea\Events\PaymentStatusUpdated;

Event::listen(PaymentStatusUpdated::class, function (PaymentStatusUpdated $event) {
    $status = Plorea::payments()->status($event->reference);

    if ($status->isPaid()) {
        // mark paid — idempotently: webhook, status poll and return page can race
    }
});
```

Delivery is best-effort — also run a scheduled job polling `status()` for open links.

## Testing

```php
Plorea::fake();

// ... exercise code ...

Plorea::assertSent('payments/link');
Plorea::assertSent(fn ($request) => $request->input('amount') === 50000);
Plorea::assertSentCount(1);

// Stub endpoints with arrays, callables, or exceptions:
Plorea::fake([
    'payments/status/*' => ['reference' => 'ref-1', 'status' => 'refused'],
    'subscriptions/*/charge' => new ChargeFailedException('Charge failed: Refused', 402),
]);
```

The fake mirrors the real API: a reference it has created a link for reports an open status (so `firstOrCreate()` works); unknown references throw `NotFoundException`. Never put real API keys in tests or fixtures.

## Errors

All exceptions extend `MemberFlow\Plorea\Exceptions\PloreaException`: `ValidationException` (400), `AuthenticationException` (401/403), `ChargeFailedException` (402), `NotFoundException` (404), `ServerException` (5xx), `ConnectionException`, `PaymentAlreadyPaidException`. HTTP errors expose `$e->status` and `$e->response?->json()`. Never log the API key or `Authorization` headers.
