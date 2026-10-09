# Subscriptions

**Plorea owns the billing schedule.** Your app stores a card, creates the
subscription, and reacts to state — it never triggers the recurring charge.
That is the single most important thing to internalise before reading on.

Prerequisite: an `active` [payment method](payment-methods.md).

## Create

```php
use MemberFlow\Plorea\Data\{Amount, BillingInterval};

$subscription = Plorea::subscriptions()
    ->create('pm_...', Amount::nok(19900), BillingInterval::monthly())
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
`nextChargeAt`, and the scheduler charges the card unless you set a trial:
within seconds in test, 15–25 minutes after create in live (7 of 7, 2026-10;
the scheduler runs about every 5 minutes). There is no "start on the 1st" option; if you need one, use a
trial that ends then.

Always set `externalId` — it is how you find the subscription again, and it is
included in the `subscription.charge_succeeded` webhook payload.

### Routing the money to a company

A platform billing on behalf of several companies names the receiving company
on the subscription, exactly as on a payment link:

```php
$subscription = Plorea::subscriptions()
    ->create('pm_...', Amount::nok(49900), BillingInterval::monthly())
    ->externalId((string) $membership->id)
    ->merchant(orgNr: '999999999', name: 'Acme Gym AS', email: 'billing@acme.example')
    ->save();

$subscription->merchantOrgNr;   // "999999999" — also returned by find() and forExternalId()
```

Every charge on the subscription, scheduled or manual, is settled to that
company. It is always the client's organisation number, never the platform's
own. Name and email are optional and used for KYC communication.

- **Verified 2026-09-21:** the fields are accepted; the create response echoes
  `merchantOrgNr` (not the name or email); an organisation number that is not
  nine digits is a `ValidationException` (`400`) at create; the first
  scheduled charge authorises as usual.
- **Returned on reads since 2026-09-22:** `find()` and `forExternalId()` carry
  the organisation number (not the name or email). `charges()` returns only
  the charge items, which do not. Still persist it when you create the
  subscription: your own record is what tells you which company to expect.
- **Stated by Plorea, not observed:** KYC for a company Plorea has not seen
  starts on the first charge; settlement splits apply from that same charge;
  charge webhooks carry the organisation number. A subscription created
  without a merchant behaves as before.
- **Stated by Plorea:** a stored payment method belongs to the shopper, not to
  a company, so one `pm_...` can back subscriptions for different companies
  under the same tenant.

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
| `paused` | `isPaused()` | Billing paused; `nextChargeAt` cleared — see [below](#pause-and-resume) |
| `canceled` | `isCanceled()` | Cancelled (US spelling) — access runs to `accessEndsAt` |
| `past_due` | `isPastDue()` | A scheduled charge failed and Plorea is retrying it — see [below](#the-dunning-gap) |
| `payment_failed` | `hasPaymentFailure()` | The last retry failed; Plorea stops — observed live 2026-10-08 |

Use the helpers or `is('...')`, never a bare string comparison.

## Find and list

```php
$subscription = Plorea::subscriptions()->find('sub_...');

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

## Pause and resume

Recommended by Plorea and captured in the test environment on 2026-10-01.
Scheduler races, in-flight charges, retries and the later billing anchor still
require verification in the consuming integration.

```php
$paused = Plorea::subscriptions()->pause($subscription->id);
$resumed = Plorea::subscriptions()->resume($subscription->id, $nextChargeAt);
```

Both use `PATCH subscriptions/{id}`. Pause sends `status: paused`, which
clears `nextChargeAt`; the card, the last charge and the amounts are kept, and
`accessEndsAt` stays null. Resume sends `status: active` and an explicit
`nextChargeAt` in the same request, and the response echoes the date. The date
is serialized as UTC with milliseconds. The caller owns its value; the SDK does
not infer paid entitlement or choose a date.

What Plorea refuses (each a 400, `ValidationException`):

- pausing anything but an `active` subscription — a trial included ("Cannot
  change status from trialing to paused");
- resuming anything but a `paused` subscription;
- resuming with a date in the past ("nextChargeAt cannot be in the past") —
  the subscription stays paused, so to bill now pass a moment a little ahead.

To move the next charge without changing the status, use
`update($id)->nextChargeAt($date)->save()`. It works on a paused subscription
(it stays paused) and on a trial — but on a trial only `nextChargeAt` moves,
`trialEndsAt` keeps its old value.

**Open — no charge was seen after resume.** On three resumed test
subscriptions the scheduler had not charged 3, 16 and 41 minutes after the
resume date, and each stayed `active` with the lapsed `nextChargeAt`. Raised
with Plorea 2026-10-01. Until it is settled, check after a resume date that the
charge landed — `needingAttention()` reports the subscription as overdue once
the grace period passes.

The fluent equivalents are `update($id)->pause()->save()` and
`update($id)->resumeAt($nextChargeAt)->save()`. Calling `pause()` after
`resumeAt()` on the same builder removes the previously set charge date.

Persist the original billing boundary before pausing, read back provider state
after uncertain responses, and schedule recovery for failed resumes. Your application chooses the date under its own entitlement rules; for example,
it may shift the saved boundary by the paused duration. Do not use cancellation/reactivation to implement this pause.
A paused subscription is excluded from `isOverdue()` even if its response has
a stale date. Pausing removes the subscription from `needingAttention()`, so track any
existing debt separately in your app. Check status and charge history before
pausing; this does not resolve debt or establish that in-flight charges stop.

Only resume a subscription confirmed as `isPaused()`. Transitions from canceled,
trialing or paused are refused; pausing while past_due and combined updates
with amount/card changes are unverified. Use `reactivate()` for cancellation recovery.
Do not blindly retry writes: a delayed resume replay could restore an old charge
date after the scheduler has advanced it. Persist intent and reconcile readback
and charges before deciding whether another write is needed.

`Plorea::fake()` returns the pause/resume response but does not persist PATCH
state for later reads. Use explicit stateful stubs for `find()` and list requests
when testing a consuming app's recovery and readback lifecycle.

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
$charges = Plorea::subscriptions()->charges($subscription->id);  // Collection<SubscriptionCharge>, not reliably ordered
$latest = $charges->sortByDesc('createdAt')->first();

$latest->status;        // "authorised" | "failed"
$latest->reason;        // "scheduled_charge" | "manual_charge"
$latest->amount;
$latest->retryNumber;   // 0 on the first attempt, 1, 2 on retries
```

Sort by `createdAt` yourself. The test capture came back newest first, but a
live read on 2026-10-08 returned the 2nd, 3rd, then 1st attempt. Failed
attempts are listed too, with `status: failed`, a `pspReference` and a
`failureReason` (observed live 2026-10).

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

- A failed scheduler charge emits `subscription.charge_failed` →
  `SubscriptionChargeFailed`, one per attempt (observed 2026-10-01 to
  2026-10-08, though Plorea had said failures were poll-only). A delivery is
  never retried, so the event speeds dunning up; polling is still what
  guarantees it.
- **Live `subscription.*` webhooks went to the test URL** until 2026-10-09,
  labelled `environment: "test"`. Plorea states the routing is fixed; this is
  not yet verified on the wire. See
  [Webhooks](webhooks.md#live-subscription-webhooks-went-to-the-test-url).
- A failed scheduled charge turns the subscription **`past_due`** (captured
  from production 2026-10-01). `retryCount` counts the failed attempts,
  `failureReason` carries the provider's message, `lastChargeAt` stays at the
  last *successful* charge, and `nextChargeAt` moves to the retry —
  `retryPolicy.retryIntervalDays` later, up to `retryPolicy.maxRetries`
  attempts. The charge appears in `charges()` with `status: failed`.
- After the last retry the subscription turns **`payment_failed`**, not
  `canceled` (observed live 2026-10-08): `retryCount: 3`, `nextChargeAt:
  null`. Plorea stops charging. Retries came about 24 hours apart. See
  [Verified API behaviour](api-behaviour.md#-after-the-last-retry-payment_failed--2026-10-06-to-2026-10-08-production).
- A retry charges the amount the subscription has **when the retry runs**, not
  the amount of the failed attempt (stated by Plorea 2026-10-09).
- Plorea can reset the retries of a `past_due` subscription and charge it again
  on request. There is no API for it. After the charge the subscription reads
  `active`, `retryCount: 0`, and `nextChargeAt` one interval after the new
  charge, so the billing day can move (observed live 2026-10-09).
- Plorea may pause a subscription while they debug, without notice (stated
  2026-10-09). A `paused` subscription you did not pause is worth a question
  to Plorea.

So dunning must poll, and must not assume the shape of what it finds.
`needingAttention()` is the polling half:

```php
// Scheduled, e.g. hourly, per billed entity.
foreach (Plorea::subscriptions()->needingAttention($workspace->externalId) as $subscription) {
    $latest = Plorea::subscriptions()->charges($subscription->id)
        ->sortByDesc('createdAt')
        ->first();

    if ($latest?->isAuthorised() !== true) {
        // Prompt for a new card. Do not branch on failureReason — it is the
        // provider's free-text message, not a stable code.
    }
}
```

It returns a subscription when any test fires:

| Test | Basis |
| --- | --- |
| `isPastDue()` — status is `past_due` | Captured from production 2026-10-01 |
| `hasPaymentFailure()` — status is `payment_failed` | Observed live 2026-10-08, after the last retry |
| `isOverdue()` — `nextChargeAt` is more than an hour in the past | Derived from fields captured on the wire |

The tests are independent. A `past_due` subscription is **not** overdue: its
`nextChargeAt` has already moved to the retry, so only the status gives it
away. The overdue test covers a cycle that stalled without any failure status —
Plorea's scheduler charges within minutes of `nextChargeAt` (seconds in test,
up to about 25 minutes in live) and moves the date forward as it does, so a
date well past the grace period means the cycle did not complete. Keep the
grace period above 25 minutes in live, or a new subscription can show as
overdue before its first charge.

Tune the grace period for your billing cadence, and pass a fixed clock in tests:

```php
Plorea::subscriptions()->needingAttention($externalId, graceMinutes: 30);

$subscription->isOverdue(graceMinutes: 30, now: $this->knownTime);
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

// 4. Start dunning on SubscriptionChargeFailed, and poll needingAttention()
//    on a schedule — a lost webhook is never redelivered.

// 5. On cancellation, keep access until accessEndsAt (or now, if it is null).
```

See also: [Payment methods](payment-methods.md) · [Webhooks](webhooks.md) · [Verified API behaviour](api-behaviour.md)
