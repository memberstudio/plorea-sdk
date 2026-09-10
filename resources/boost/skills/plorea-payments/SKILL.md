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

The create response does not echo the merchant back; the org number only
reappears on the pay page (`Plorea::payByLink()->find($id)->merchantOrgNr`).

Prefer `->firstOrCreate()` over `->create()`: Plorea has no idempotency on duplicate references. It returns an existing open link as-is, supersedes dead or amount-changed links with a suffixed reference (`-1`, `-2`, ...), and throws `MemberFlow\Plorea\Exceptions\PaymentAlreadyPaidException` when the reference is already paid — catch it, never create a fresh link for a settled invoice. The check-then-create is not atomic; wrap in `Cache::lock()` per reference if double submits are possible.

## Status

```php
$status = Plorea::payments()->status('FIN-2026-00123');

$status->isPaid();             // authorised or paid — money moved
$status->isOpen();             // created, pending, active — still payable
$status->isRefundRequested();  // accepted, provider settling asynchronously
$status->isCancelRequested();
```

Use the helpers, not string comparison. A **refused payment reports `failed`, not `refused`** (captured 2026-09-09) — `$status->is('failed')`; the decline itself shows as `webhookEventCode: AUTHORISATION` with `webhookSuccess: false`, and there is no `failureReason` field on this shape. `expired` never appears live — links past expiry keep reporting open; judge expiry from the stored `expiresAt`. The `webhookEventCode` / `webhookSuccess` / `lastWebhookAt` fields describe Plorea's inbound Adyen webhook, not webhooks sent to your app. Verify `$status->amount` against your local expectation before booking; on mismatch, flag for manual handling.

## Refund / cancel

```php
Plorea::payments()->refund('FIN-2026-00123', modificationReference: 'FIN-2026-00123-refund-1', amount: Amount::nok(450000), reason: '...');
Plorea::payments()->cancel('FIN-2026-00123', 'FIN-2026-00123-cancel-1');
```

Both return immediately with `refund_requested` / `cancel_requested`; the provider settles asynchronously — poll `status()` or wait for a webhook. **Settlement takes hours** (>11h observed 2026-09-09), so size polling loops in hours: an hour-long timeout reports a healthy refund as failed, and reversing your books or telling the customer on that basis does real damage. `refund_requested` means accepted. There is no per-refund status field — pre-settlement the only signal is top-level `status` plus the `lastRefund*` group, and `webhookEventCode` stays `AUTHORISATION` because it describes the original authorisation.

**Persist the `Refund` DTO.** `refundPspReference` exists only on the refund response and never on `payments/status`, whose `lastRefundRequestPspReference` identifies the *request* rather than the refund. No later poll can recover it, and it is the identifier provider support asks for.

## Embedded checkout

`Plorea::payByLink()->session($linkId, returnUrl: ...)` returns a live Adyen session — `pay.plorea.no` is just a Drop-in mounted on one — so you can render checkout on your own domain. **But `$session->clientKey` comes back `null`**, and lifting the key from the hosted page does not work either: Adyen scopes client keys to an allowed-origins list you are not on, so the Drop-in mounts and then fails the `/sessions/{id}/setup` preflight with CORS, looking nothing like a credential problem. Embedding needs Plorea to whitelist your origins or issue you a key — ask before building. The redirect flow is the supported path today (verified 2026-09-09).

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
$method->isPendingSetup();  // customer has not finished the flow
$method->isActive();        // card stored — cardLast4, cardBrand, expiryDate populated
$method->hasFailed();       // verification refused, no card stored
```

Both flows authorise and reverse a 1 NOK verification charge to store the
card; the customer is never billed for it. `failed` is terminal — start a new
setup, never retry the charge. Creating or updating a subscription with a
non-active method fails with a 400 `ValidationException` ("Payment method is
not active"). `failureReason` is null even on a real refusal, so branch on
`status` alone. Validation messages are static templates — one missing field
still lists all five — so never parse them.

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
Plorea::subscriptions()->charge($subscription->id, reason: 'extra_seat');
```

Plorea owns the billing schedule — your app never triggers the recurring
charge. Billing starts immediately: `create()` already returns the first
`nextChargeAt` and the scheduler charges within seconds unless a trial is set.
`reactivate()` sets the subscription active again and recomputes
`nextChargeAt` — it does not resume the old cadence. It used to charge
unconditionally, billing the same period twice on cancel-then-reactivate;
fixed by Plorea and verified on the test environment 2026-09-09 (single
cancel → reactivate, charge count unchanged over a 10-minute poll,
`nextChargeAt` set one interval after the settled charge). Reactivating an
already-active subscription is refused with a 400 `ValidationException`
("Only canceled subscriptions can be reactivated"), so that shape cannot
charge either. Production is still unobserved, so guarding on `accessEndsAt`
stays cheap insurance.

With `trialUntil()` the subscription is created `trialing` with `nextChargeAt`
equal to `trialEndsAt` — nothing is charged until the trial ends, and a
trialing subscription is not `isActive()`. Cancelling during a trial leaves
`accessEndsAt` **null** (it is derived from the last charge, and there is
none) — treat null as "access ends now", not "never ends".

Statuses are `active`, `trialing` and `canceled` (US spelling) — use `isActive()`,
`isCanceled()`, `hasPaymentFailure()`, or `is('...')`, never string
comparison. Cancelling clears `nextChargeAt` and sets `accessEndsAt` one
interval after the last charge; gate access on that date, not on
`canceledAt`.

Charges: the POST returns `status: charge_created` with Adyen's answer in
`resultCode`, while `charges()` history items report the settled `status`
(`authorised`) and a `reason` of `manual_charge` or `scheduled_charge`. A
declined card throws `ChargeFailedException` (402); a subscription that is not
chargeable at all throws `ValidationException` (400) — different failures,
handle them differently.

Each charge produces a payment referenced `{subscriptionId}-{chargeId}`, also
on `$subscription->lastPaymentReference`, resolvable via
`Plorea::payments()->status(...)` — but **only for manual charges**. A
scheduler-created reference does not resolve: 403 "Tenant mismatch" on
2026-09-04, 404 "Payment not found" when retested 2026-09-09 against a fresh
authorised charge. Always read scheduled outcomes from `charges()`; dunning
must poll that, never `payments()->status()`.

### Dunning

A failed scheduler charge emits no webhook, so poll for it:

```php
foreach (Plorea::subscriptions()->needingAttention($workspace->externalId) as $subscription) {
    $latest = Plorea::subscriptions()->charges($subscription->id)->first();

    if ($latest?->isAuthorised() !== true) {
        // prompt for a new card — do not branch on failureReason, it is null
        // even for a genuine refusal
    }
}
```

`needingAttention()` returns a subscription reporting `payment_failed` **or**
one whose `nextChargeAt` is more than `$graceMinutes` (default 60) in the past.
Lean on the overdue half: `payment_failed`, `failureReason` and `retryCount`
are modelled from Plorea's documentation and have never been observed, because
no test card can store successfully and then decline. The scheduler charges
within seconds of `nextChargeAt` and moves the date forward, so a date still in
the past means the cycle did not complete whatever the status ends up being.
Canceled subscriptions are never overdue.

Neither `forExternalId()` nor `charges()` has been observed to paginate, and
neither sends paging parameters. Verify large result sets against your own
records.

## Webhooks

The package registers `POST /plorea/webhook` and verifies the `X-Plorea-Signature` header (base64 HMAC-SHA256 of the raw body, secret used as the key verbatim) against `PLOREA_WEBHOOK_SECRET`. It fails closed without a secret; `PLOREA_WEBHOOK_VERIFY=false` is staging-only. Exclude `plorea/webhook` from CSRF verification.

Plorea's catalogue (confirmed 2026-09-09) has four types, with more planned: `payment.authorised`, `payment.failed`, `payment.refunded` → `PaymentStatusUpdated`; `subscription.charge_succeeded` → `SubscriptionChargeSucceeded`. Everything else arrives as `WebhookReceived` only.

Every event carries `$event->eventId` and `$event->type`, read from the request **body**. Deduplicate on `$event->eventId` — the signature covers the raw body only, so the `x-plorea-event-id` header can be rewritten on an otherwise valid delivery and keying on it would let a replay through twice.

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

### Subscription webhooks

Verified from real deliveries (2026-09-04). A **scheduler** charge emits
`subscription.charge_succeeded` → `SubscriptionChargeSucceeded`. A **manual**
`charge()` emits `payment.authorised` → `PaymentStatusUpdated`. Plorea
confirmed on 2026-09-09 that nothing is emitted for card setup (success or
failure), cancel, reactivate, or a **failed** scheduler charge — those are
poll-only. A failed recurring charge never announces itself, so poll
`subscriptions()->needingAttention()` if you need dunning.

```php
use MemberFlow\Plorea\Events\SubscriptionChargeSucceeded;

Event::listen(SubscriptionChargeSucceeded::class, function (SubscriptionChargeSucceeded $event) {
    $subscription = Plorea::subscriptions()->find($event->subscriptionId);

    // extend access idempotently — ->status, ->nextChargeAt, ->accessEndsAt
    // $event->externalId links back to your own entity
});
```

`SubscriptionChargeSucceeded` does not also raise `PaymentStatusUpdated`:
firing both would double-book the same charge, and the `{subId}-{chgId}`
reference does not resolve for scheduler charges anyway. Read it from
`charges()`.

Every other `subscription.*` type arrives only as `WebhookReceived` — branch on
`type` and re-fetch. Never rely on webhooks alone for billing state; schedule a
job that refreshes active subscriptions via `find()` / `forExternalId()`.

## Testing

```php
Plorea::fake();

// ... exercise code ...

Plorea::assertSent('payments/link');
Plorea::assertSent(fn ($request) => $request->input('amount') === 50000);
Plorea::assertSentCount(1);

// Stub endpoints with arrays, callables, or exceptions:
Plorea::fake([
    'payments/status/*' => ['reference' => 'ref-1', 'status' => 'failed'],
    'subscriptions/*/charge' => new ChargeFailedException('Charge failed: Refused', 402),
]);
```

The fake mirrors the real API: a reference it has created a link for reports an open status (so `firstOrCreate()` works); unknown references throw `NotFoundException`. Never put real API keys in tests or fixtures.

## Errors

All exceptions extend `MemberFlow\Plorea\Exceptions\PloreaException`: `ValidationException` (400), `AuthenticationException` (401/403), `ChargeFailedException` (402), `NotFoundException` (404), `ServerException` (5xx), `ConnectionException`, `PaymentAlreadyPaidException`. HTTP errors expose `$e->status` and `$e->response?->json()`. Never log the API key or `Authorization` headers.

**A 401 is not always about the key.** The `X-Environment` header selects which Adyen credentials Plorea uses; omit it on `POST payments/refund` and the call routes to **live** credentials and returns `401` with `errorType: "security"`, with nothing in the body pointing at the environment (observed 2026-09-10). The SDK sets the header on every request, so this only bites raw `curl` reproductions — which is exactly what you reach for when a 401 has you doubting your key. Check the header before rotating anything.
