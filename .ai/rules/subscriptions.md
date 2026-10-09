# Subscriptions and payment methods

API shapes **VERIFIED 2026-09-04**, captured from the test environment,
anonymised into `tests/Fixtures/` and asserted in `GoldenFixturesTest`.

## Statuses

Payment methods: `pending_setup` → `active` | `failed`, and `active` →
`cancelled` by `delete()` (UK spelling, unlike subscriptions). **`failed` is
terminal** — the 1 NOK setup verification auth was refused and no card was
stored, so start a new setup rather than retrying the charge. `failureReason`
is null even on a real refusal, so branch on `status` alone.

Subscriptions: `active` / `trialing` / `past_due` / `canceled` (US spelling).
`past_due` = a scheduled charge failed and Plorea is retrying (CAPTURED from
production 2026-10-01, fixture `subscription-past-due.json`).
`accessEndsAt` = last charge + one interval; gate access on that, not on
`canceledAt`.

## Billing

Billing starts **immediately** on create — `create()` already returns the first
`nextChargeAt` and the scheduler charges unless a trial is set: within
seconds in test, **15–25 min after create in live** (7 of 7 no-trial
subscriptions, 2026-10; the scheduler runs about every 5 min). Plorea owns the
schedule; the app never triggers a recurring charge.

**Incident, live 2026-10-01 to 2026-10-09 — FIXED:** every scheduled live
charge failed — 12 attempts, all `failureReason: "PaymentDetail not found"`,
on cards that were stored and activated without error. **Stated by Plorea
2026-10-09:** the scheduler charged live subscriptions against Adyen's
**test** environment, where the live cards do not exist. The `pspReference`s
on those failed items are test references; nothing reached live, no bank
declined and no money moved. Fixed 2026-10-09 with a separate live scheduler
and a guard that rejects a charge whose environment does not match.
VERIFIED 2026-10-09 (live): the retried charges came back `authorised`. This
was an incident, **not** API behaviour: do not model it, and do not read
`"PaymentDetail not found"` as a card problem.

**Stated by Plorea 2026-10-09:** Plorea may **pause** subscriptions
themselves while they debug, without telling the merchant. That explains the
`past_due` subscriptions seen `paused` on 2026-10-09. Do not assume your app
paused a subscription it finds paused.

**OPEN, seen 2026-10-06 (test):** new no-trial subscriptions, including one
on a card the scheduler had charged before, were **not** charged. Each
scheduler run pushed `nextChargeAt` ~15–20 min forward instead, with no
charge, no `failureReason` and `retryCount` 0. A manual charge on the same
subscription was authorised. Raised with Plorea. The overdue check does not
catch this, because the date keeps moving forward.

Charge POST returns `status: charge_created` + `resultCode`; history items
return `status: authorised` + `reason: manual_charge|scheduled_charge`. The
charge payment reference is `{subId}-{chgId}`.

Failed charges are listed too — VERIFIED live 2026-10-01 to 2026-10-08: every
failed attempt is an item with `status: failed`, a `pspReference` and a
`failureReason`.

**`charges()` is not reliably ordered.** The test capture
(`subscription-charges.json`, n=2) is newest first; a live read on 2026-10-08
returned the 2nd, 3rd, then 1st attempt. Sort by `createdAt`; never take
`->first()` as the latest charge.

**A not-chargeable subscription and a not-active payment method both return
400, not 402.** 402 is a card decline only — different failures, handled
differently (`ValidationException` vs `ChargeFailedException`).

Validation messages are static templates — one missing field still lists all
five — so never parse them.

## Merchant routing — VERIFIED 2026-09-21, reads 2026-09-22

`->merchant(orgNr, name, email)` sends `merchantOrgNr` / `merchantName` /
`merchantEmail` on `POST subscriptions`. The create response echoes
**`merchantOrgNr` only**, as the last key (`subscription-created-merchant.json`)
— unlike payment links, which echo nothing. Not nine digits → 400.

On 2026-09-21 reads did not return it. **Since 2026-09-22 they do**
(`subscription-merchant.json`, `subscription-list-merchant.json`,
`subscription-charges-merchant.json`): `find()` and each list item carry
`merchantOrgNr`; the charge history carries it **once, on the envelope**
(`{subscriptionId, merchantOrgNr, items}`), not on each item. `charges()`
returns only the items, so the envelope value is not exposed — read it from
`find()`. A subscription created before merchant routing has the key with
`null`. The name and email are never returned. The fake echoes the org number
on reads of a subscription it created with one.

Only three fields are accepted as far as anyone knows — do not add `phone` /
`country` to match `PendingPaymentLink::merchant()`.

**Stated by Plorea 2026-09-21, UNOBSERVED:** every charge inherits the company;
KYC starts on the first charge; splits apply from that charge; charge webhooks
carry the org number (do not model an accessor until one is captured); a
stored method is tied to the shopper, not a company, and can be charged for
several org numbers under one tenant.

## Card setup takes `merchantOrgNr` + `merchantName` — Stated by Plorea 2026-09-29/30

Without it, the 3D Secure challenge on card setup shows the wrong company
name. `->merchant(orgNr, name)` on the setup builder sends `merchantOrgNr` /
`merchantName` on **both** `payment-methods/setup/session` and the hosted
`payment-methods/setup` (Plorea confirmed the hosted endpoint 2026-09-30: it
maps the org number to the company's store, and the hosted page shows the
company's name on 3DS and on the page). Org number and name only; no email
was asked for. UNOBSERVED on the wire: whether the session response echoes it, and
whether a non-nine-digit value is rejected. The fake does not model either.

## Delete — CAPTURED 2026-10-06 (test)

`paymentMethods()->delete()` → `DELETE payment-methods/{id}`, fixtures
`payment-method-deleted*.json`, `payment-method-delete-in-use.json`,
`payment-method-cancelled.json`.

- A 400 carrying `activeSubscriptionIds` becomes `PaymentMethodInUseException`
  (extends `ValidationException`). **Keyed on that field, never on the
  message.** Trialing subscriptions block it as well.
- A repeat delete is a 200 with `alreadyCancelled: true`, not an error.
- A sibling method sharing the same `storedPaymentMethodId` stays `active`
  and **can still be charged** — a manual charge on it was `Authorised` after
  the delete (CAPTURED 2026-10-06, test). Production unobserved.
- **Stated by Plorea 2026-10-09:** the delete cancels only the Plorea method.
  It does **not** deactivate the stored card or token at Adyen today. Plorea
  says they will change that. **Risk once they do:** two methods can share one
  `storedPaymentMethodId`, so deleting one may also break the sibling. Before
  deleting a method that shares its stored id with an active one, confirm with
  Plorea how the change treats a shared token.
- The cancelled method stays readable, card and stored id included. The read
  has no `cancelledAt`; the delete response does.
- A bare 404 `{"message":"Not Found"}` is API Gateway's "no such route" (seen
  before the route was deployed 2026-10-05). The real not-found is
  `{"error":"Payment method not found", ...}`.

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

## Dunning: `needingAttention()` = `past_due` + `payment_failed` + overdue

`subscriptions()->needingAttention($externalId)` returns a subscription when it
reports `past_due` or `payment_failed`, **or** when `Subscription::isOverdue()` fires —
`nextChargeAt` more than `$graceMinutes` (default 60) in the past, canceled
subscriptions excluded because they keep a stale date.

`past_due` is **not** overdue: Plorea moves `nextChargeAt` to the retry
(failure + `retryIntervalDays`), so the status check is required — CAPTURED
2026-10-01. `payment_failed` — VERIFIED live 2026-10-06 to 2026-10-08: 3
attempts about 24 h apart (the slot drifts ~5 min each time), then
`payment_failed` (not `canceled`), `retryCount: 3`, `nextChargeAt: null`.
`lastChargeAt` does not move on a retry. Each charge item's `retryNumber` is
0, 1, 2; the webhooks carry `retryCount` 1, 2, 3. No fixture.

**A retry charges the current amount** — Stated by Plorea 2026-10-09: a retry
uses the amount the subscription has when the retry runs, not the amount of
the failed attempt. An amount change between attempts changes what the retry
charges.

**Manual retry reset** — on request, Plorea can reset the retries of a
`past_due` subscription and charge it again (done 2026-10-09). VERIFIED live
2026-10-09: after the successful charge the subscription read `active`,
`retryCount: 0`, and `nextChargeAt` one interval after the **new** charge
time. The billing anchor moved (a monthly cycle on the 8th moved to the 9th).
There is no API for the reset; ask Plorea. Persist your own period boundary
if the anchor matters.
The overdue test stays **on purpose**, for a cycle that stalls without any
failure status; `nextChargeAt` is captured. The scheduler charges within
minutes of the due time (seconds in test, up to ~25 min in live) and moves the
date forward as it does, so a date well past the grace window means the cycle
did not complete whatever Plorea names the status.
**Do not reduce this to a status check, nor drop the status checks.**

It deliberately sends no `status` filter to the list endpoint: filtering
server-side on one failure status could drop the overdue subscriptions the
helper exists to find.

It says *which* subscriptions to look at, never *why*. Read `charges()` for
that — `SubscriptionCharge::isAuthorised()` is the predicate.

## Neither list endpoint has been seen to paginate — OPEN, probed 2026-09-10

`GET subscriptions` → `{count, items}` plus an echo of the filter you queried
by (`externalId` or `tenantId`); `GET subscriptions/{id}/charges` →
`{subscriptionId, items}`. No cursor, page, offset or `hasMore` key on either,
and no paging parameters documented.

A deliberate probe confirmed both shapes and settled nothing else: listing by
tenant gave `count: 3` against 3 items, and the longest available history was 3
charges. At n=3 the two agree under either reading. The charges endpoint is the
more exposed: with no count at all a truncated history is invisible, and a
monthly subscription accumulates history forever.

**Do not invent paging parameter names.** The SDK sends none, both docblocks
say the claim is unverified, and `GoldenFixturesTest` asserts
`count === count($items)` as a tripwire — a future capture where they diverge
fails the build.

Settling it needs a page-crossing dataset, which cannot be manufactured without
spamming real Adyen test subscriptions. Cheapest path: leave one daily-interval
subscription running (~30 charges/month unattended) and read its charges later.
**A wait, not a probe — do not burn time trying to force it.**

## Dead end — do NOT retry (verified 2026-09-08)

**There is no way to produce a failing subscription charge in test.**

Adyen has no amount-based refusal triggering for ecom cards — that mechanism is
in-person/terminal only. The only documented levers are a magic
`paymentMethod.holderName` or `additionalData.RequestedTestAcquirerResponseCode`,
and both must ride on the `/payments` request, i.e. at setup time. Plorea's
scheduler charges the stored PM server-side with no passthrough for either —
the amount is the only lever a caller has.

Empirically confirmed: the holderName `NOT_ENOUGH_BALANCE` trick refuses the
setup verification itself, so the PM never activates
(`storedPaymentMethodId: null`). An active-but-later-declining card is
unreachable.

Therefore the 402 body and any real card decline are blocked on Plorea
provisioning a store-OK-decline-later test card (asked 2026-09-08, dropped
2026-10-07). The 402 shape is modelled from documentation, not observed —
write dunning code that tolerates a slightly different shape.

The failure path itself was observed in **production** 2026-10-01 to
2026-10-08: `past_due`, `retryCount`, `failureReason`, the
`subscription.charge_failed` webhook (misrouted to the test URL until
2026-10-09 — see [webhooks.md](webhooks.md)), and `payment_failed` after the
last retry. All were `"PaymentDetail not found"` (the fixed incident above:
charges sent to Adyen's test environment), not a decline. A real decline is
still unobserved, and it still cannot be produced in test.

## Native pause — CAPTURED 2026-10-01 (test)

`PATCH subscriptions/{id}` with `status: paused` clears `nextChargeAt`; resume
is `status: active` plus `nextChargeAt` in one PATCH. SDK helpers are `pause()`
and `resume($id, $nextChargeAt)`, or builder `pause()` / `resumeAt()` /
`nextChargeAt()`. Full matrix in `docs/api-behaviour.md`; golden fixtures
`subscription-paused*.json`, `subscription-resumed.json`,
`subscription-pause-trialing-refused.json`, `subscription-resume-past-date.json`,
`subscription-next-charge-moved-trialing.json`.

- Only `active` ⇄ `paused`. Pausing `trialing`, `canceled` or `paused`, and
  resuming anything not paused, is a 400. Any other status value is a 400.
- Resume with a past date is a 400 and the subscription stays paused. Resume
  without a date is a 400 unless one was set while paused.
- `nextChargeAt` alone is accepted on paused (stays paused) and trialing
  (`trialEndsAt` does not move).
- A paused read keeps `lastChargeAt` and the card; `accessEndsAt`/`canceledAt`
  stay null; there is no `pausedAt`.
- **OPEN: no charge was seen after resume** — 3, 16 and 41 minutes past the
  date, status `active`, date lapsed. Raised with Plorea. Do not document
  resume as "billing restarts" until a post-resume charge is observed; the
  overdue check is what catches it meanwhile.
- **Narrowed 2026-10-04/05 (test):** not specific to resume. On an `active`
  subscription that has already been charged, a moved `nextChargeAt` (plain
  PATCH, or pause then resume; `.000Z` and `+00:00` alike) is stored but was
  not charged in four scheduler runs after the date. Overnight with daily
  subscriptions, the moved ones were not charged on the original date either,
  while the unmoved baseline was. A `trialing` subscription with a moved date
  is charged in the next run. Do not rely on moving the date of an active
  subscription until a charge on a moved date is observed.

Persist the original boundary before the provider clears it. In-flight charges,
retry cancellation, pausing `past_due`, later billing anchors and combined
updates (amount/card with status) are unverified. Do not assume a repeated
resume is safe after the scheduler has advanced the date.
