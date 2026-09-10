# Subscriptions

**Plorea owns the billing schedule.** Your app stores a card, creates the
subscription, and reacts to state — it never triggers the recurring charge.
That is the single most important thing to internalise before reading on.

Prerequisite: an `active` [payment method](payment-methods.md).

## Create

```php
use MemberFlow\Plorea\Data\{Amount, BillingInterval};

$subscription = Plorea::subscriptions()
    ->create('pm_63cd...', Amount::nok(19900), BillingInterval::monthly())
    ->externalId('ws_acme_456')            // your own entity id — the link back
    ->title('Done CRM Pro')
    ->description('5 seats')
    ->quantity(5)
    ->vat(rate: 0.25, amount: 3980)
    ->retryPolicy(3, retryIntervalDays: 2)
    ->metadata(['plan' => 'pro'])
    ->save();

$subscription->id;            // sub_...
$subscription->status;        // "active"
$subscription->nextChargeAt;
```

Intervals: `BillingInterval::daily()`, `weekly()`, `monthly()`, `yearly()`,
each taking an optional count — `BillingInterval::monthly(3)` is quarterly.

**Billing starts immediately.** The create response already carries the first
`nextChargeAt`, and the scheduler charges the card within seconds unless you
set a trial. There is no "start on the 1st" option; if you need one, use a
trial that ends then.

Always set `externalId` — it is how you find the subscription again, and it is
included in the `subscription.charge_succeeded` webhook payload.

## Trials

```php
$subscription = Plorea::subscriptions()
    ->create($methodId, Amount::nok(19900), BillingInterval::monthly())
    ->trialUntil(now()->addDays(14))
    ->save();

$subscription->status;        // "trialing"
$subscription->isTrialing();  // true
$subscription->isActive();    // false — trialing is a distinct status
$subscription->nextChargeAt;  // exactly $subscription->trialEndsAt
```

Nothing is charged until the trial lapses. Gate access on `isTrialing() ||
isActive()`, never on `isActive()` alone.

## Statuses

| Status | Helper | Meaning |
| --- | --- | --- |
| `trialing` | `isTrialing()` | In trial, nothing charged yet |
| `active` | `isActive()` | Billing on schedule |
| `canceled` | `isCanceled()` | Cancelled (US spelling) — access runs to `accessEndsAt` |
| `payment_failed` | `hasPaymentFailure()` | Modelled, **never observed** — see [below](#the-dunning-gap) |

Use the helpers or `is('...')`, never a bare string comparison.

## Find and list

```php
$subscription = Plorea::subscriptions()->find('sub_774c...');

$subscriptions = Plorea::subscriptions()->forExternalId('ws_acme_456', status: 'active');
$subscriptions = Plorea::subscriptions()->forExternalId('ws_acme_456', tenantId: 'other-tenant');
```

`forExternalId()` returns an `Illuminate\Support\Collection` of `Subscription`.

## Update

```php
Plorea::subscriptions()->update($subscription->id)
    ->amount(Amount::nok(39900))
    ->quantity(10)
    ->vat(rate: 0.25, amount: 7980)
    ->paymentMethod('pm_new...')
    ->save();
```

Only the fields you set are sent. The interval cannot be changed — cancel and
create a new subscription for that.

## Cancel

```php
$cancellation = Plorea::subscriptions()->cancel($subscription->id, 'customer_requested');

$cancellation->canceledAt;
$cancellation->accessEndsAt;  // end of the period already paid for
```

Cancelling clears `nextChargeAt` and sets `accessEndsAt` to one billing
interval after the **last charge**. Gate access on `accessEndsAt`, not on
`canceledAt`.

> [!IMPORTANT]
> Cancelling during a **trial** returns `accessEndsAt: null` — it is derived
> from the last charge and a trial has none. Treat null as "access ends now",
> not "access never ends". This is the single easiest way to accidentally grant
> a cancelled trialist permanent access.

```php
$endsAt = $cancellation->accessEndsAt ?? now();
```

## Reactivate

```php
$subscription = Plorea::subscriptions()->reactivate($subscription->id);
```

Reactivation sets the subscription active again and **recomputes**
`nextChargeAt` — it does not resume the original cadence.

> [!NOTE]
> **History worth knowing.** `reactivate()` used to charge unconditionally,
> billing a customer twice for a period they had already paid — observed on a
> daily subscription reactivated 33 seconds after cancelling.
>
> Fixed by Plorea and verified against the test environment on 2026-09-09: a
> single cancel → reactivate on a subscription with one settled charge left the
> charge count unchanged across a 10-minute poll, and set `nextChargeAt` to
> exactly one interval after that charge. Reactivating an **already active**
> subscription is now refused outright — 400 `ValidationException`, "Only
> canceled subscriptions can be reactivated".
>
> Production remains unobserved, and the guard costs nothing:

```php
if ($subscription->accessEndsAt?->isFuture()) {
    // Still inside a paid period — no need to reactivate at all.
    return;
}

Plorea::subscriptions()->reactivate($subscription->id);
```

## Charges

### Manual, off-schedule

```php
$charge = Plorea::subscriptions()->charge(
    $subscription->id,
    amount: Amount::nok(4900),   // omit to charge the subscription amount
    reason: 'extra_seat',
);

$charge->status;      // "charge_created" — the request was accepted
$charge->resultCode;  // "Authorised" — Adyen's answer
$charge->reference;   // "sub_…-chg_…"
```

Note the two-level answer: `status` describes Plorea's acceptance of the
request, `resultCode` is the acquirer's. Read `resultCode` for the money.

### History

```php
$charges = Plorea::subscriptions()->charges($subscription->id);  // Collection<SubscriptionCharge>, newest first

$charges->first()->status;   // "authorised"
$charges->first()->reason;   // "scheduled_charge" | "manual_charge"
$charges->first()->amount;
$charges->first()->retryNumber;
```

`charges()` is the authoritative record of scheduled billing. **Poll it.**

### Looking a charge up as a payment

Each charge produces a payment referenced `{subscriptionId}-{chargeId}`, also
on `$subscription->lastPaymentReference`.

```php
$status = Plorea::payments()->status($subscription->lastPaymentReference);
```

> [!WARNING]
> **This works for manual charges only.** A scheduler-created reference does
> not resolve: 403 "Tenant mismatch" on 2026-09-04, and 404 "Payment not found"
> when retested on 2026-09-09 against a fresh, authorised, same-tenant
> scheduler charge — twice, 25 minutes apart, with the reference taken verbatim
> from the charge's own `reference` field.
>
> Read scheduled outcomes from `charges()`. Whether the lookup *should* resolve
> is an open question with Plorea.

## Failures

```php
use MemberFlow\Plorea\Exceptions\{ChargeFailedException, ValidationException};

try {
    Plorea::subscriptions()->charge($subscription->id);
} catch (ChargeFailedException $e) {
    // 402 — the card was declined. A payment problem.
} catch (ValidationException $e) {
    // 400 — the subscription is not chargeable at all (cancelled, or the
    // payment method is not active). A state problem, not a card problem.
}
```

The distinction matters: a 402 means dunning, a 400 means your app sent a
request it should not have sent. Both a not-chargeable subscription and a
not-active payment method return **400**, not 402.

### The dunning gap

Read this before building retry logic.

- There is **no webhook for a failed scheduler charge**. Plorea confirmed on
  2026-09-09 that failures are poll-only.
- `payment_failed`, a populated `failureReason`, and a non-zero `retryCount`
  are all modelled from Plorea's documentation but have **never been
  observed** — no test-environment card can store successfully and then
  decline. See [Verified API behaviour](api-behaviour.md#what-cannot-be-reproduced-in-test).

So dunning must poll, and must not assume the shape of what it finds.
`needingAttention()` is the polling half:

```php
// Scheduled, e.g. hourly, per billed entity.
foreach (Plorea::subscriptions()->needingAttention($workspace->externalId) as $subscription) {
    $latest = Plorea::subscriptions()->charges($subscription->id)->first();

    if ($latest?->isAuthorised() !== true) {
        // Prompt for a new card. Do not branch on failureReason — it is null
        // even for a genuine refusal.
    }
}
```

It returns a subscription when either test fires:

| Test | Basis |
| --- | --- |
| `hasPaymentFailure()` — status is `payment_failed` | Modelled from Plorea's documentation, **never observed** |
| `isOverdue()` — `nextChargeAt` is more than an hour in the past | Derived from fields captured on the wire |

The second test is the one carrying the weight. Plorea's scheduler charges
within seconds of `nextChargeAt` and moves the date forward as it does, so a
date still in the past means the cycle did not complete — whatever Plorea ends
up calling the status. If the documented failure shape turns out to be wrong,
the overdue check still fires.

Tune the grace period for your billing cadence, and pass a fixed clock in tests:

```php
Plorea::subscriptions()->needingAttention($externalId, graceMinutes: 15);

$subscription->isOverdue(graceMinutes: 15, now: $this->knownTime);
```

A canceled subscription is never overdue — it keeps whatever `nextChargeAt` it
had when scheduling stopped. Being overdue tells you the cycle did not
complete, not why; read `charges()` for that, never `payments()->status()`,
which cannot resolve a scheduler charge at all.

## A complete flow

```php
// 1. Store the card.
$method = Plorea::paymentMethods()
    ->setup($user->id, RecurringType::Subscription, route('billing.return'))
    ->create();
// redirect to $method->adyenPaymentLinkUrl, then poll until isActive()

// 2. Subscribe.
$subscription = Plorea::subscriptions()
    ->create($method->id, Amount::nok(19900), BillingInterval::monthly())
    ->externalId("workspace:{$workspace->id}")
    ->trialUntil(now()->addDays(14))
    ->save();

$workspace->update(['plorea_subscription_id' => $subscription->id]);

// 3. Extend access on each successful scheduled charge.
Event::listen(SubscriptionChargeSucceeded::class, function ($event) {
    $subscription = Plorea::subscriptions()->find($event->subscriptionId);
    Workspace::where('plorea_subscription_id', $subscription->id)
        ->first()?->extendAccessTo($subscription->nextChargeAt);  // idempotently
});

// 4. Poll on a schedule — for failures, which never arrive as webhooks.

// 5. On cancellation, keep access until accessEndsAt (or now, if it is null).
```

See also: [Payment methods](payment-methods.md) · [Webhooks](webhooks.md) · [Verified API behaviour](api-behaviour.md)
