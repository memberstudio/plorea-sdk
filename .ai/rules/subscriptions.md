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

Billing should start on create — `create()` already returns the first
`nextChargeAt`. In **production** a no-trial subscription is charged on the
scheduler's next run, **15–25 min after create** (7 of 7 no-trial
subscriptions, 2026-10; the scheduler runs about every 5 min; verified again
2026-10-10: charge history `scheduled_charge`, `authorised`). In **test** it
was not charged on 2026-10-06 (below). Never write that billing starts
"immediately" without that qualification.
Plorea owns the schedule; the app never triggers a recurring charge.

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
- **Stated by Plorea 2026-10-09:** the delete cancelled only the Plorea method;
  the stored card at Adyen stayed active.
- **Stated by Plorea 2026-10-14 (not tested by us):** in **test only**, a delete
  now also disables the card at Adyen when no other active payment method or
  subscription uses it. A shared `storedPaymentMethodId` stays until every
  method using it is deleted. The response has `adyenTokenDisabled`
  (`PaymentMethodCancellation::$adyenTokenDisabled`, null when absent). Live
  follows later; until Plorea confirms live, assume live still keeps the card.
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

Trial end is charged — VERIFIED 2026-10-11 (test), but ~22 min late: the
scheduler first pushed `nextChargeAt` +17 min with no charge and no
`failureReason`, then charged. The new `nextChargeAt` is the pushed time + 1
interval, so the anchor drifts by the delay. Read `nextChargeAt` back; never
compute the next period from `trialEndsAt`.

Cause of the delay — Stated by Plorea 2026-10-20: create stored `trialEndsAt`
as sent (`+02:00`) and the scheduler compared it as text against UTC. A minimum
wait of 15 min after create also applied. Plorea fixed create (PATCH was
already correct). All dates now come back in UTC (`…T12:02:42.000Z`). A date
without an offset, or one that does not exist (30 Feb), gives 400. The SDK
sends `trialEndsAt` in UTC with `Z`, like `nextChargeAt`. Not yet re-observed
on the wire: keep reading `nextChargeAt` back.

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

**Update, VERIFIED 2026-10-19 (test, one charge):** a scheduler-created
reference (`reason: scheduled_charge`, charged 2026-10-10) now resolves, with
`platform: subscription`, `subscriptionId`, `chargeId`, `refundedTotal`,
`refundStatus` and `refunds[]`. One charge in test is not a contract, and
production is unobserved. Keep `charges()` as the source for dunning.

Read scheduled outcomes from `charges()`. **Dunning must poll that, never
`payments()->status()`.**

## Dunning: `needingAttention()` = `past_due` + `payment_failed` + overdue

`subscriptions()->needingAttention($externalId)` returns a subscription when it
reports `past_due` or `payment_failed`, **or** when `Subscription::isOverdue()` fires —
`nextChargeAt` more than `$graceMinutes` (default 60) in the past, canceled
subscriptions excluded because they keep a stale date.

`past_due` is **not** overdue: Plorea moves `nextChargeAt` to the retry
(failure + `retryIntervalDays`), so the status check is required — CAPTURED
2026-10-01. `payment_failed` was observed in production 6–8 October 2026: three
attempts about 24 hours apart (`retryCount` 3, `nextChargeAt` null), not
`canceled`. The slot drifts ~5 min each time; `lastChargeAt` does not move on
a retry; each charge item's `retryNumber` is 0, 1, 2 and the webhooks carry
`retryCount` 1, 2, 3. That is observed, not confirmed by Plorea as a fixed policy. A retry
charges the amount held when it runs (Stated by Plorea, 9 October). PATCH
`status: active` on `payment_failed` → 400 "Cannot change status from
payment_failed to active" (VERIFIED in production, Sentry 12–17 October).
`cancel()` on `payment_failed` → `canceled`, `nextChargeAt` and `accessEndsAt`
null when never charged (VERIFIED in production 2026-10-20; charge, refund and
webhook side effects UNOBSERVED). The way back to billing is cancel + create a
new subscription on the same payment method with a trial to the wanted day
(see "Moving the billing day" below). Other ways out are **untested**: PATCH `amount` + `nextChargeAt`;
`resume()` (verified only paused → active; an active one gives 400,
`allowedTransitions: ["paused"]`); `reactivate()` (verified only from canceled,
400 "Only canceled subscriptions can be reactivated"). Plorea's manual retry
reset (9 October, on request) gave `active` / `retryCount` 0, charged **at
once**, and set `nextChargeAt` one interval after the **new** charge time, so
the billing anchor moved (a monthly cycle on the 8th moved to the 9th). No API
does that; persist your own period boundary if the anchor matters. Never document any route out of
`payment_failed` as working. The overdue test
stays **on purpose**, for a cycle that stalls without any failure status;
`nextChargeAt` is captured. The scheduler charges within
minutes of a natural due time (seconds in test, up to ~25 min in production) and moves the date forward as it does, so a date still
in the past means the cycle did not complete whatever Plorea names the status.
**Do not reduce this to a status check, nor drop the status checks.**

It deliberately sends no `status` filter to the list endpoint: filtering
server-side on one failure status could drop the overdue subscriptions the
helper exists to find.

It says *which* subscriptions to look at, never *why*. Read `charges()` for
that — `SubscriptionCharge::isAuthorised()` is the predicate.

## Moving the billing day — VERIFIED in test 2026-10-20

No call moves the billing day. Cancel the old subscription, then create a new
one on the same payment method with `trialEndsAt` = the wanted day. No new
consent. New id: the integrator must relink. First charge 4m49s after
`trialEndsAt`; after it `nextChargeAt` = `currentPeriodEnd` = `trialEndsAt` +
interval (the anchor holds). A later cancel gave `accessEndsAt` = **actual charge
time** + interval, not anchor + interval. Production 2026-10-20: the new
subscription read `trialing`, `nextChargeAt` = `trialEndsAt`; its first charge
is UNOBSERVED. Do not quote the ids in the repo.

## `externalId` filter ignored with `tenantId` — VERIFIED 2026-10-20

`GET subscriptions?externalId=X&tenantId=Y` returned the whole tenant (120 rows,
2 matches). Without `tenantId` the filter works. `forExternalId()` drops rows
whose `externalId` differs and keeps rows without one; `needingAttention()`
goes through it. **Do not remove that filter** when Plorea fixes the bug: it
costs nothing and a regression would bill-flag other customers.

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

Therefore the 402 body and a producible `payment_failed` subscription are
**both** blocked on Plorea provisioning a
store-OK-decline-later test card (asked 2026-09-08, dropped 2026-10-07).
The 402 body is modelled from documentation, not observed — write dunning code
that tolerates a slightly different shape.

The failure path itself was observed in **production** 2026-10-01 to
2026-10-08: `past_due`, `retryCount`, `failureReason`, the
`subscription.charge_failed` webhook (misrouted to the test URL until
2026-10-09 — see [webhooks.md](webhooks.md)), and `payment_failed` after the
last retry. All were `"PaymentDetail not found"` (the fixed incident above:
charges sent to Adyen's test environment), not a decline. A real decline is
still unobserved, and it still cannot be produced in test.

**The test-key list showed live subscriptions** (no environment filter, seen
2026-10-11), including live `payment_failed` ones. Since 2026-10-13 the API key
sets the environment and lists show only the key's environment (stated by
Plorea). An ID from the other environment returns `404` (observed in
production 2026-10-13). Still check `environment` before any write in an
experiment. `Subscription::$environment` maps it from `GET` responses (added by
Plorea 2026-10-13; null on older responses). `payment_failed` recovery
(PATCH amount/date, `resume`) is still UNOBSERVED in test.

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
  date, status `active`, date lapsed. Raised with Plorea (1 and 5 October,
  still open). Do not document resume as "billing restarts" until a
  post-resume charge is observed; the overdue check is what catches it
  meanwhile. Recipes that rely on a moved date (fixed billing day, resume with
  a future date) must carry the open-question badge.
- **Narrowed 2026-10-04/05 (test):** not specific to resume. On an `active`
  subscription that has already been charged, a moved `nextChargeAt` (plain
  PATCH, or pause then resume; `.000Z` and `+00:00` alike) is stored but was
  not charged in four scheduler runs after the date. Overnight with daily
  subscriptions, the moved ones were not charged on the original date either,
  while the unmoved baseline was. A `trialing` subscription with a moved date
  is charged in the next run. Do not rely on moving the date of an active
  subscription until a charge on a moved date is observed. Changing `amount`
  and `nextChargeAt` in one update is unverified.

Persist the original boundary before the provider clears it. In-flight charges,
retry cancellation, pausing `past_due`, later billing anchors and combined
updates (amount/card with status) are unverified. Do not assume a repeated
resume is safe after the scheduler has advanced the date.

## 2026-10-14 fields in the SDK

- `Subscription::$currentPeriodEnd` — VERIFIED in test 2026-10-14: equal to
  `nextChargeAt` on active, charged subscriptions; absent on trialing and
  canceled ones. The fake omits it, because its default subscription has no
  charge. Stated by Plorea 2026-10-20: it is null until the first charge, and
  `GET /subscriptions` (the list) now returns it too.
- `PaymentMethod` card fields — VERIFIED in test 2026-10-14 on a read. The
  months and years are integers. The fake returns them for a plain card.
- **409 with `paidUntil`** on `PATCH` — Stated by Plorea, not captured.
  `RequestException::fromResponse()` maps a 409 **only when `paidUntil` is
  present** to `NextChargeInPaidPeriodException`; any other 409 stays a plain
  `RequestException`. `allowEarlyCharge()` sends `allowEarlyCharge: true`.
  Replace the constructed test body with a fixture once a 409 is captured.
