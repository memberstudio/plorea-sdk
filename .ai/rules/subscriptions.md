# Subscriptions and payment methods

API shapes **VERIFIED 2026-09-04**, captured from the test environment,
anonymised into `tests/Fixtures/` and asserted in `GoldenFixturesTest`.

## Statuses

Payment methods: `pending_setup` → `active` | `failed`. **`failed` is
terminal** — the 1 NOK setup verification auth was refused and no card was
stored, so start a new setup rather than retrying the charge. `failureReason`
is null even on a real refusal, so branch on `status` alone.

Subscriptions: `active` / `trialing` / `canceled` (US spelling).
`accessEndsAt` = last charge + one interval; gate access on that, not on
`canceledAt`.

## Billing

Billing starts **immediately** on create — `create()` already returns the first
`nextChargeAt` and the scheduler charges within seconds unless a trial is set.
Plorea owns the schedule; the app never triggers a recurring charge.

Charge POST returns `status: charge_created` + `resultCode`; history items
return `status: authorised` + `reason: manual_charge|scheduled_charge`. The
charge payment reference is `{subId}-{chgId}`.

**A not-chargeable subscription and a not-active payment method both return
400, not 402.** 402 is a card decline only — different failures, handled
differently (`ValidationException` vs `ChargeFailedException`).

Validation messages are static templates — one missing field still lists all
five — so never parse them.

## Trials — VERIFIED 2026-09-07

`trialUntil()` → status `trialing` (a fourth status), `nextChargeAt` ==
`trialEndsAt` exactly, nothing charged until it lapses. A trialing subscription
is **not** `isActive()`.

Cancelling during a trial returns **`accessEndsAt: null`** — it is derived from
the last charge, and there is none. Treat null as "access ends now", not
"never ends".

`CardOnFile` / `UnscheduledCardOnFile` are echoed back verbatim on setup.

## `reactivate()` no longer double-bills — FIXED by Plorea, verified 2026-09-09

It previously charged unconditionally: cancel → reactivate 33s later drew a
second charge for the same day on a DAILY subscription. Retested after the fix
(single cancel → reactivate, charge count unchanged over a 10-minute poll,
`nextChargeAt` set one interval after the settled charge).

Reactivating an already-active subscription is now refused with 400 ("Only
canceled subscriptions can be reactivated").

Production remains unobserved, so guarding on an elapsed `accessEndsAt` stays
cheap insurance in consuming apps.

## Scheduler charges do not resolve through the payment status endpoint

Reported to Plorea 2026-09-04. `payments/status/{subId}-{chgId}` fails for
**scheduler-created** charges — 403 "Tenant mismatch" on 2026-09-04, 404
"Payment not found" on retest 2026-09-09. Manual charges resolve fine.

Read scheduled outcomes from `charges()`. **Dunning must poll that, never
`payments()->status()`.**

## Dunning: `needingAttention()` leans on overdue, not on `payment_failed`

`subscriptions()->needingAttention($externalId)` returns a subscription when it
reports `payment_failed` **or** when `Subscription::isOverdue()` fires —
`nextChargeAt` more than `$graceMinutes` (default 60) in the past, canceled
subscriptions excluded because they keep a stale date.

The overdue test is the load-bearing one **on purpose**. `payment_failed` is
modelled from Plorea's documentation and has never been observed (see the dead
end below), while `nextChargeAt` is captured. The scheduler charges within
seconds of the due time and moves the date forward as it does, so a date still
in the past means the cycle did not complete whatever Plorea names the status.
If the documented failure shape turns out to be wrong, the overdue check still
fires. **Do not reduce this to a status check.**

It deliberately sends no `status` filter to the list endpoint: filtering
server-side on a never-observed value could drop the overdue subscriptions the
helper exists to find.

It says *which* subscriptions to look at, never *why*. Read `charges()` for
that — `SubscriptionCharge::isAuthorised()` is the predicate.

## Neither list endpoint has been seen to paginate — OPEN

`GET subscriptions` → `{externalId, count, items}`; `GET
subscriptions/{id}/charges` → `{subscriptionId, items}`. No cursor, page,
offset or `hasMore` key on either, and no paging parameters documented.

Every capture is small (1 subscription, 2 charges), so `count` and
`count($items)` agree and nothing distinguishes "everything" from "page one".
The charges endpoint is the more exposed: with no count at all a truncated
history is invisible, and a monthly subscription accumulates history forever.

**Do not invent paging parameter names.** The SDK sends none, both docblocks
say the claim is unverified, and `GoldenFixturesTest` asserts
`count === count($items)` as a tripwire — a future capture where they diverge
fails the build.

## Dead end — do NOT retry (verified 2026-09-08)

**There is no way to produce a failing subscription charge in test.**

Adyen has no amount-based refusal triggering for ecom cards — that mechanism is
in-person/terminal only. The only documented levers are a magic
`paymentMethod.holderName` or `additionalData.RequestedTestAcquirerResponseCode`,
and both must ride on the `/payments` request, i.e. at setup time. Plorea's
scheduler charges the stored PM server-side with no passthrough for either, and
we control only the amount.

Empirically confirmed: the holderName `NOT_ENOUGH_BALANCE` trick refuses the
setup verification itself, so the PM never activates
(`storedPaymentMethodId: null`). An active-but-later-declining card is
unreachable.

Therefore the 402 body, a `payment_failed` subscription, `retryCount` /
`failureReason`, and any `subscription.charge_failed` webhook are **all**
blocked on Plorea provisioning a store-OK-decline-later test card (asked
2026-09-08). Those shapes are modelled from documentation, not observed — write
dunning code that tolerates a slightly different shape.
