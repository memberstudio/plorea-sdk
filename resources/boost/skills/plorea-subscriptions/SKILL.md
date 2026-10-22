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
2. **Start** — creates the subscription. The first charge should follow at once; confirm it in `charges()` (seen in production, not in test on 2026-10-06).
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
- **Billing should start at once.** The create response already carries the first
  `nextChargeAt`. A no-trial signup was charged at once in production
  (2026-10-10), but in test on 2026-10-06 new no-trial subscriptions were not
  charged (open question). Confirm the first charge in `charges()`. There is no
  start-date option; a later start is a trial (below). Do not move `nextChargeAt`
  on an `active` subscription to fake one (open question).
- The charge is made against the stored card. The customer is not asked for
  anything at this point.
- **Billing for several companies?** Add
  `->merchant(orgNr: ..., name: ..., email: ...)` — always the client's
  organisation number, never your own. Every charge on the subscription is
  settled to that company, and Plorea starts KYC on the first charge for a
  company it has not seen. The create response, `find()` and `forExternalId()`
  return `merchantOrgNr` (charge items do not); still store it on your own
  record when you create. Not nine digits is a `ValidationException`. One stored card can back
  subscriptions for different companies under the same tenant.
- **Guard against creating it twice.** Write the local record before calling
  Plorea. If the call times out or the request is repeated, look before you
  create again:

```php
$existing = Plorea::subscriptions()->forExternalId((string) $membership->id)->first();
```

  `forExternalId()` drops rows for another `externalId`: with `tenantId` set,
  Plorea ignored the filter and returned the whole tenant (2026-10-20). Calling
  the endpoint yourself? Filter the rows on `externalId` too.

  Keep `PLOREA_RETRY_TIMES=0` for this call. Whether Plorea de-duplicates on
  `externalId` is unobserved, so do not rely on it.

## 3. Grant access on a charge, not on create

`create()` answers `active` before any money has moved. Treat it as "scheduled".

```php
Event::listen(SubscriptionChargeSucceeded::class, function ($event) {
    // dedupe on $event->eventId
    // since 2026-10-14 a manual charge lands here too: skip renewal receipts when
    // $event->isManualCharge()
    $subscription = Plorea::subscriptions()->find($event->subscriptionId);
    $charge = Plorea::subscriptions()->charges($subscription->id)->sortByDesc('createdAt')->first();

    if ($charge?->isAuthorised()) {
        Membership::find($event->externalId)
            ?->markPaidThrough($subscription->nextChargeAt, $charge->reference);  // idempotent
    }
});
```

- The webhook is a ping. Re-fetch, and book idempotently on the charge
  reference: the webhook and the reconciliation job can both see the same charge.
- Read scheduled charges from `charges()`. A scheduler-created
  `{subId}-{chgId}` reference did not resolve through `payments()->status()`
  until 2026-10-19 (now seen resolving for one test charge).
- Refund a subscription charge with `payments()->refund()` on its
  `{subId}-{chgId}` reference (verified 2026-10-19, test). Never `cancel()` a
  charged one: the cancel was refused by Adyen, but the charge still read
  `cancelled` while the money stayed charged.
- A **manual** `charge()` raised `PaymentStatusUpdated` in captures before
  2026-10-14, and its reference resolves through `payments()->status()`.
  Whether `payment.authorised` still comes is unobserved. Since 2026-10-14,
  Plorea states that a manual charge also sends `subscription.charge_succeeded`
  or `subscription.charge_failed` with `reason: "manual_charge"` (not tested by
  us). Check `$event->isManualCharge()` before you treat the event as a renewal.
- `update($id)->nextChargeAt($date)->save()` with a date inside a paid period
  throws `NextChargeInPaidPeriodException` (409, `paidUntil()`). Add
  `->allowEarlyCharge()` only when the customer agreed to pay again (stated by
  Plorea 2026-10-14). `$subscription->currentPeriodEnd` is the paid period's end.
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

The SDK sends `trialEndsAt` in UTC. Before Plorea's 2026-10-20 fix, a date
with an offset such as `+02:00` was charged late; Plorea now returns UTC dates
and rejects a date without an offset with 400. Read `nextChargeAt` back after
the trial charge; do not compute the next period from `trialEndsAt`.

**Moving the billing day** has no verified API call. Cancel the old subscription, then
create a new one on the same payment method with `trialUntil()` = the new day,
on or after the old `accessEndsAt` (an earlier day charges the overlap twice).
No new consent is needed. The new subscription has a new id: store it in place
of the old one. Verified in test 2026-10-20: the first charge came about 5
minutes after the trial end, and `nextChargeAt` then kept the chosen day.

## 4. Reconciliation: the only way to see a failure

Nothing is emitted for a **failed** scheduled charge, a cancel, a reactivate or
card setup. Schedule a job, per billed entity:

```php
foreach (Plorea::subscriptions()->needingAttention($externalId) as $subscription) {
    $latest = Plorea::subscriptions()->charges($subscription->id)->sortByDesc('createdAt')->first();

    if ($latest?->isAuthorised() !== true) {
        // start your dunning: notify, grace period, ask for a new card
    }
}
```

`needingAttention()` returns a subscription that reports `past_due` or
`payment_failed` (seen in production after three failed attempts about 24 hours
apart; not confirmed by Plorea as a fixed policy) **or** whose `nextChargeAt` is more
than an hour past (`graceMinutes:` to tune). A failed scheduled charge turns
the subscription `past_due` (`isPastDue()`): `retryCount` counts the failures,
`failureReason` holds the provider's message, and `nextChargeAt` moves to the
retry, so it is not overdue. Do not branch on a failure reason. No verified API
route takes `payment_failed` back to billing: PATCH `status: active` answers 400
"Cannot change status from payment_failed to active". `cancel()` works (status
`canceled`, `accessEndsAt` null if never charged); then create a new
subscription on the same payment method (see "Moving the billing day"). Other
ways out are untested: PATCH `amount` + `nextChargeAt`,
`resume()` (verified only from `paused`) and `reactivate()` (verified only from
`canceled`). Plorea's manual reset charged at once and moved the billing anchor,
and has no API. A retry charges the amount held when it runs (stated by
Plorea).

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
- Pause with `subscriptions()->pause($id)`; resume with `resume($id, $nextChargeAt)`.
  Pause clears `nextChargeAt` and keeps the card. Only `active` ⇄ `paused` is
  allowed: pausing a trial is a 400 (move its date with
  `update($id)->nextChargeAt()` instead), and a past resume date is a 400.
  Persist the original billing boundary first and choose the resume date under
  your application's entitlement rules. Only resume a confirmed `isPaused()`
  subscription. Reconcile uncertain writes before retrying; a late replay can
  restore an old date after a charge. Track existing debt separately because
  paused subscriptions disappear from `needingAttention()`.
  Captured in test 2026-10-01. No charge has yet been seen after a resume, or
  after any moved `nextChargeAt` on an `active`, already-charged subscription
  (open with Plorea since 5 October; only `trialing` ones were charged): do not
  rely on it for a fixed billing day or a resume date, verify the charge lands, and rely on
  `needingAttention()` to flag it once overdue. Verify in-flight charges,
  retries, future billing anchors, past_due pauses and combined updates (such as
  `amount` with `nextChargeAt`, unverified) before
  rollout. For recovery tests, use stateful
  stubs: the default fake does not persist subscription PATCH state to reads.
- Proration is yours to compute. A one-off difference can be taken with a manual
  `charge($id, amount: ..., reason: ...)`; a declined manual charge throws
  `ChargeFailedException` (402).

## Cancelling

```php
$cancellation = Plorea::subscriptions()->cancel($id, 'customer_requested');
$endsAt = $cancellation->accessEndsAt ?? now();
```

`accessEndsAt` is one interval after the last charge, timed from when the card
was actually charged (minutes after the planned day); gate on it, not on
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
- On a multi-company platform: `->merchant()` set on every subscription, and
  the organisation number stored locally.
- Access follows an authorised charge (or your trial rule), never `create()`.
- Charge webhook deduplicated on `$event->eventId`, booked idempotently.
- A scheduled job runs `needingAttention()` and settles missed charges.
- Dunning keys on overdue, not on a failure reason.
- Duplicate-start guard in place; `PLOREA_RETRY_TIMES=0`.
- Null `accessEndsAt` handled as "ends now".
