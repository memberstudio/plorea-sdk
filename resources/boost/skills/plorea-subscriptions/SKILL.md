---
name: plorea-subscriptions
description: "Use this skill when building or reviewing recurring billing on the Plorea Payments SDK (memberflow/plorea) in a Laravel app: memberships, plans or seats billed to a stored card. Trigger when starting a subscription from a saved card, deciding when to grant access, activating on the first charge, handling trials or delayed start dates, building dunning for failed recurring charges, cancelling, pausing, changing plan, price or card, avoiding duplicate subscriptions, or writing the scheduled reconciliation job. Covers the lifecycle and the state your own app must keep; for the individual API calls use the plorea-payments skill."
license: MIT
metadata:
  author: memberflow
---
# Recurring billing with Plorea

The `plorea-payments` skill lists the calls. This one is the order to make them
in, and the state your app has to keep around them. Plorea owns the schedule:
your app stores a card, creates the subscription, and reacts. It never triggers
a recurring charge.

## The shape of an integration

Keep a local record per billed entity (membership, workspace, seat plan) with
its own status, and the Plorea `subscription id` on it. Four moving parts:

1. **Card setup** — stores the card. Poll-only, no webhook.
2. **Start** — creates the subscription. The first charge follows within seconds.
3. **Settlement** — the first and every later charge, by webhook *and* by poll.
4. **Reconciliation** — a scheduled job. It is the only way a failed charge is
   ever seen.

## 1. Store the card first

```php
$method = Plorea::paymentMethods()->find($pendingMethodId);

$method->isActive();        // chargeable
$method->isPendingSetup();  // customer has not finished
$method->hasFailed();       // terminal — start a new setup
```

Only an `active` method can carry a subscription; anything else is a 400
`ValidationException` on create, not a 402. Hosted, embedded and native card
setup are in the `plorea-checkout` skill.

## 2. Start: record intent, then create

```php
$membership = Membership::create([..., 'status' => 'pending']);   // no access yet

$subscription = Plorea::subscriptions()
    ->create($method->id, Amount::nok(49900), BillingInterval::monthly())
    ->externalId((string) $membership->id)
    ->title($plan->name)
    ->save();

$membership->update(['plorea_subscription_id' => $subscription->id]);
```

- **Always set `externalId`** to your own entity's id. It comes back on the
  charge webhook and is how you find the subscription again.
- **Billing starts immediately.** The create response already carries the first
  `nextChargeAt` and the card is charged within seconds. There is no start-date
  option; a later start is a trial (below).
- The charge is made against the stored card. The customer is not asked for
  anything at this point.
- **Guard against creating it twice.** Write the local record before calling
  Plorea. If the call times out or the request is repeated, look before you
  create again:

```php
$existing = Plorea::subscriptions()->forExternalId((string) $membership->id)->first();
```

  Keep `PLOREA_RETRY_TIMES=0` for this call. Whether Plorea de-duplicates on
  `externalId` is unobserved, so do not rely on it.

## 3. Grant access on a charge, not on create

`create()` answers `active` before any money has moved. Treat it as "scheduled".

```php
Event::listen(SubscriptionChargeSucceeded::class, function ($event) {
    // dedupe on $event->eventId
    $subscription = Plorea::subscriptions()->find($event->subscriptionId);
    $charge = Plorea::subscriptions()->charges($subscription->id)->first();

    if ($charge?->isAuthorised()) {
        Membership::find($event->externalId)
            ?->markPaidThrough($subscription->nextChargeAt, $charge->reference);  // idempotent
    }
});
```

- The webhook is a ping. Re-fetch, and book idempotently on the charge
  reference: the webhook and the reconciliation job can both see the same charge.
- Read scheduled charges from `charges()`. A scheduler-created
  `{subId}-{chgId}` reference does not resolve through `payments()->status()`.
- A **manual** `charge()` raises `PaymentStatusUpdated` instead, and its
  reference does resolve through `payments()->status()`.
- Do not wait on the webhook alone for the first charge. Poll `charges()` a few
  times after create so the customer sees the result, then leave it to the job.

## Trials and delayed starts

```php
->trialUntil($membership->billing_starts_at)   // status "trialing", nothing charged
```

`nextChargeAt` equals `trialEndsAt` exactly. A trialing subscription is **not**
`isActive()` — gate on `isTrialing() || isActive()`. Whether a trial grants
access is your app's rule, decided when you start it, since no charge will
arrive to trigger it.

## 4. Reconciliation: the only way to see a failure

Nothing is emitted for a **failed** scheduled charge, a cancel, a reactivate or
card setup. Schedule a job, per billed entity:

```php
foreach (Plorea::subscriptions()->needingAttention($externalId) as $subscription) {
    $latest = Plorea::subscriptions()->charges($subscription->id)->first();

    if ($latest?->isAuthorised() !== true) {
        // start your dunning: notify, grace period, ask for a new card
    }
}
```

`needingAttention()` returns a subscription that reports `payment_failed` **or**
whose `nextChargeAt` is more than an hour past (`graceMinutes:` to tune). Build
on the overdue half. `payment_failed`, `failureReason` and `retryCount` are
modelled from documentation and have not been observed, because no test card
stores successfully and then declines. Do not branch on a failure reason.

The same job should also settle anything the webhook missed: a pending entity
whose latest charge is authorised gets activated here.

**A failed first charge** must leave no access and a recoverable entity: keep
the pending record, let the customer store a new card, and switch the existing
subscription to it rather than creating a second one.

## Changing things

```php
Plorea::subscriptions()->update($id)->amount(Amount::nok(59900))->save();   // price, quantity, VAT
Plorea::subscriptions()->update($id)->paymentMethod($newMethod->id)->save(); // new card (must be active)
```

- Only the fields you set are sent.
- **The interval cannot be changed.** Moving between monthly and yearly is a
  cancel plus a new subscription; mind the period already paid for.
- The SDK has no pause call. Model a pause in your own app (for example cancel, and
  start again with a trial until the resume date) and test what it does to
  `accessEndsAt`.
- Proration is yours to compute. A one-off difference can be taken with a manual
  `charge($id, amount: ..., reason: ...)`; a declined manual charge throws
  `ChargeFailedException` (402).

## Cancelling

```php
$cancellation = Plorea::subscriptions()->cancel($id, 'customer_requested');
$endsAt = $cancellation->accessEndsAt ?? now();
```

`accessEndsAt` is one interval after the last charge; gate on it, not on
`canceledAt`. **It is null when a trial is cancelled** — that means access ends
now, not never. A binding period is your app's rule: Plorea cancels when asked,
so refuse or defer the call yourself.

`reactivate()` sets a canceled subscription active and recomputes
`nextChargeAt`. If `accessEndsAt` is still in the future there is nothing to
reactivate yet; an already-active subscription answers 400.

## Testing

`Plorea::fake()` answers `POST subscriptions` with an `active` subscription
echoing your payload, or `trialing` when `trialEndsAt` is in the future, and
`charges()` with one authorised scheduled charge. It never delivers a webhook,
so dispatch the event yourself, and stub the history for the unpaid case:

```php
event(new SubscriptionChargeSucceeded('sub_1', 'chg_1', 'sub_1-chg_1', (string) $membership->id, []));

Plorea::fake(['subscriptions/*/charges' => ['subscriptionId' => 'sub_1', 'items' => []]]);  // nothing charged yet
```

The fake's subscription **list** always answers with one subscription echoing
the `externalId` you asked for. A duplicate-start guard built on
`forExternalId()` therefore finds a match in every test unless you stub it:

```php
Plorea::fake(['GET subscriptions' => ['items' => []]]);   // nothing exists yet — the start should create
```

Cover at least: no access while pending; activation by webhook; activation by
the job when no webhook arrives; both for the same charge; a repeated start
creating one subscription (`Plorea::assertSentCount`); a method that is not
active; an overdue first charge; cancel during a trial.

## Checklist

- `externalId` set on every subscription.
- Access follows an authorised charge (or your trial rule), never `create()`.
- Charge webhook deduplicated on `$event->eventId`, booked idempotently.
- A scheduled job runs `needingAttention()` and settles missed charges.
- Dunning keys on overdue, not on a failure reason.
- Duplicate-start guard in place; `PLOREA_RETRY_TIMES=0`.
- Null `accessEndsAt` handled as "ends now".
