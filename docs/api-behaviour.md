# Verified API behaviour

Field notes from running the SDK against Plorea's live test environment. Every
claim here is dated and says how it was established. This page exists because
the Plorea API has behaviours that are surprising, undocumented, or contradict
what you would reasonably assume — and because knowing what has **not** been
verified is as important as knowing what has.

Anonymised captures of the real responses live in `tests/Fixtures/` and are run
through the SDK's DTOs by `tests/Feature/GoldenFixturesTest.php`. If Plorea
changes a shape, those tests fail.

**Legend:** ✅ observed on the wire · 📋 stated by Plorea, not observed ·
❌ known impossible to observe in test · ❓ open question

---

## Payments

### ✅ A refused payment reports `failed`, not `refused` — 2026-09-09

Captured from a real payment link paid with a card Adyen declines after a
completed 3DS2 challenge.

```json
{ "status": "failed", "webhookEventCode": "AUTHORISATION", "webhookSuccess": false }
```

Three things matter:

1. The status string is `failed`. Earlier versions of this SDK's documentation
   used `refused` in examples — that string never appears, so
   `$status->is('refused')` is dead code.
2. The decline is carried by the **inbound-webhook** fields:
   `webhookEventCode: "AUTHORISATION"` with `webhookSuccess: false`. Those
   fields describe the Adyen webhook *Plorea* received.
3. There is **no `failureReason`-style field** anywhere on the payment status
   shape. `status` is the only signal.

`isPaid()` and `isOpen()` both correctly return false, so money logic was never
affected. Fixture: `payment-status-refused.json`.

### ✅ No idempotency on duplicate references

Creating two payment links with the same reference produces two live, payable
links. This is why `firstOrCreate()` exists and why retries are off by default.

### ✅ Amounts are minor units (øre)

`10000` is 100,00 kr. There is no major-unit representation anywhere in the API.

### ✅ Status endpoint: 200 for open, 404 for unknown

An open, never-paid link returns HTTP 200. Only a genuinely unknown reference
404s.

### ✅ `expired` is never reported — links stay "open" forever

A link past its expiry keeps reporting an open status. Judge expiry from the
`expiresAt` returned at creation, or from the pay page's computed `expired`
flag. The status string will not tell you.

### ✅ Refunds and cancellations settle asynchronously

`refund_requested` / `cancel_requested` appear immediately and persist until
the provider settles — which can take a long time in test. Not a failure.

**How long is "a long time": more than 11 hours, observed 2026-09-09.** A
`POST payments/refund` returns `refund_requested` with a `refundPspReference`
right away, and the payment then sat unsettled for over eleven hours before
`payment.refunded` fired. Nothing was wrong with the refund.

This matters more than it looks, because it sets the horizon for any polling
loop. A job that polls a refund every few minutes and gives up after an hour —
a completely reasonable-looking design — will conclude the refund failed on a
refund that is merely in flight. Poll on a scale of hours, or wait for the
webhook and treat polling as the backstop. Never surface "refund failed" to a
user or reverse it in your own books on a timeout alone; `refund_requested`
means accepted, not pending-and-possibly-doomed.

Whether the test environment's latency reflects production is unknown.

**There is no per-refund status field.** Three independent captures of a
refund-requested payment returned an identical 29-key body, cross-checked
against this repo's fixtures on 2026-09-09 — same keys, same order, and every
one of them read by `PaymentStatus`. The refund's own PSP reference
(`refundPspReference`) exists only on the `POST payments/refund` response;
`payments/status` carries `lastRefundRequestPspReference`, which identifies the
request rather than the refund. Persist the `Refund` DTO — that identifier
cannot be recovered from a later poll.

What the top-level status becomes *after* settlement is still unobserved; the
only refund anyone has watched was still in flight after eleven hours.

### ✅ `merchantOrgNr` is not echoed back on create — 2026-09-07

The create response omits `merchantOrgNr` and `merchantName` entirely. They
appear only on `GET pay/{id}`, which also gains a populated `partnerSplits`
once a merchant is attached.

### 📋 `merchantOrgNr` is contractually required — Plorea, 2026-08-30

The API does **not** enforce it: a link created without one is accepted and
even issues a session (verified). The SDK enforces it in `create()` anyway,
because a link that cannot be paid out is worse than a loud failure. An earlier
"Invalid Store" / 422-at-session regression was a Plorea-side KYC bug, fixed
2026-08-30 and verified the same day.

### 📋 `platform` belongs on every request — Plorea, 2026-09-15

Internal logging and reporting on Plorea's side, with no functional effect on
any endpoint. The SDK adds it to every `POST` and `PATCH` body from
`plorea.platform`: in the body on `POST` and `PATCH`, and in the query string
on `GET`, which has no body to carry it. Reads therefore have a different URL
than they did before — `payments/status/FIN-1?platform=...` — which matters if
you assert exact URLs anywhere.

It was also **not** the cause of the `401` on refund and cancel. That turned out
to be an Adyen credentials problem inside Plorea's test environment — the same
outage that took `pay.plorea.no` down. Both were fixed on their side
(`pay.plorea.no` verified back up 2026-09-15).

### 📋 Capture is automatic; there is no capture API — Plorea, 2026-09-15

Capture runs through AmendoPOS, typically within minutes of authorization. A
manual capture endpoint is on Plorea's roadmap and does not exist today, so
`authorised` is the end of the story from the SDK's side. No `capture()` method
exists, and adding one would have nothing to call.

### 📋 Embedded and native checkout are supported, on request — Plorea, 2026-09-15

This supersedes the blocker recorded on 2026-09-09 above. Plorea issues the
Adyen client key (`ADYEN_CLIENT_KEY_TEST` / `_LIVE`) and whitelists the origins
you give them, which is what the Drop-in's `/sessions/{id}/setup` preflight was
failing on. A native Adyen SDK flow additionally needs `channel: "iOS"` or
`"Android"` on `POST payments/session` — that is what makes the response carry a
usable `clientKey` — plus your bundle-id / package-name whitelisted in their
Adyen account. A WebView on the hosted pay page needs none of this; it is the
`Web` channel already in use.

**Update 2026-09-16.** The SDK now sends `channel` when asked (`Channel`), on
both `payments/session` and `payment-methods/setup/session`, and resolves the
client key from the response or `plorea.adyen_client_key`. That was a decision to
build ahead of observation; the shapes are still 📋, not ✅:

- A native-channel session response, and the `clientKey` in it: **unobserved**.
- `channel` on `payment-methods/setup/session`: asked, **unconfirmed**.
- A custom-scheme (non-https) `returnUrl`: **unobserved**.
- `environment` on a live session: **unobserved**. Adyen Web expects `live`
  for Europe, which is what `X-Environment` already uses.
- A web Drop-in mounted on a whitelisted origin end to end: **unobserved**.

Most of that list was answered the next day — see below.

### ✅ `channel` is accepted and ignored; a session carries no key — 2026-09-17

Probed against Plorea test on 2026-09-17, on both session endpoints:

- **`channel` is not read.** `Web`, `iOS`, `Android`, a lowercase `ios`, an
  unknown string and an integer all return `200`. Nothing rejects a value, and
  nothing in the response echoes one.
- **No `clientKey` for any channel.** `payments/session` returns
  `clientKey: null` for native channels too; `payment-methods/setup/session`
  has no `clientKey` key at all. The key Plorea issues out of band is the only
  key there is today, for web and native alike.
- **`environment` is `test`**, from both endpoints.
- **`payments/session` requires an http(s) `returnUrl`.** A custom scheme is
  rejected with `400` and `{"error": "returnUrl must be a valid http(s) URL"}`.
  `payment-methods/setup/session` accepts the same custom-scheme URL — the two
  endpoints validate differently.

So the native path Plorea described (2026-09-15) is **not live in test**. The
SDK still sends `channel`, since it is ignored rather than rejected, and the
configured key is what a native app must use until Plorea's side lands.

Still unobserved: a client key that works from a whitelisted origin, a Drop-in
mounted end to end, and a live `environment` value.

---

## Payment methods

### ✅ Statuses: `pending_setup` → `active` | `failed` — 2026-09-04

`failed` is terminal. The 1 NOK setup verification authorisation was refused,
so no card was stored and `storedPaymentMethodId` stays null. No retry of that
method can succeed.

### ✅ `failureReason` is null even for a genuine refusal — 2026-09-09

Branch on `status` alone.

### ✅ Validation messages are static templates — 2026-09-09

A request missing two required fields answers with a list of **all five**.
Never parse a Plorea validation message.

---

## Subscriptions

### ✅ Statuses: `active`, `trialing`, `canceled` — 2026-09-04 / 2026-09-07

US spelling. `trialing` is a distinct fourth state, not a flavour of active.

### ✅ Billing starts immediately

The create response already carries the first `nextChargeAt`, and the scheduler
charges within seconds unless a trial is set.

### ✅ Trials — 2026-09-07

`trialUntil()` produces status `trialing` with `nextChargeAt` exactly equal to
`trialEndsAt`, and nothing is charged until it lapses. Cancelling **during** a
trial returns `accessEndsAt: null`, because it is derived from the last charge
and there is none. Treat null as "access ends now".

### ✅ Charge shapes — 2026-09-04

`POST .../charge` returns `status: "charge_created"` with Adyen's answer in
`resultCode`. History items from `charges()` report the settled
`status: "authorised"` with `reason: "manual_charge" | "scheduled_charge"`.
A charge's payment reference is `{subscriptionId}-{chargeId}`.

### ✅ Not-chargeable returns 400, not 402 — 2026-09-04

Both a not-chargeable subscription and a not-active payment method return
**400**. 402 is a card decline and nothing else.

### ✅ Reactivate: fixed, then hardened — 2026-09-09

`reactivate()` used to charge unconditionally, billing a customer twice for a
period they had already paid. Originally observed on a DAILY subscription
reactivated 33 seconds after cancelling; scheduler lag between reactivation and
the resulting charge ranged from 7 seconds to about 4.5 minutes across runs.

Fixed by Plorea and verified 2026-09-09: a single cancel → reactivate on a
subscription with one settled charge left the charge count unchanged across a
10-minute poll, and set `nextChargeAt` to exactly one interval after that
charge. Reactivating an **already active** subscription now returns 400,
"Only canceled subscriptions can be reactivated", so neither shape can
double-charge.

Production remains unobserved. Guarding on an elapsed `accessEndsAt` still
costs nothing.

### ❓ Scheduler-charge references do not resolve as payments

`payments/status/{subId}-{chgId}` answered **403 "Tenant mismatch"** on
2026-09-04 and **404 "Payment not found"** when retested on 2026-09-09 against
a fresh, authorised, same-tenant scheduler charge — twice, 25 minutes apart,
with the reference taken verbatim from the charge's own `reference` field.

**Manual** charges resolve there normally: `payment-status-subscription-charge.json`
is a real capture, returning `platform: "subscription"` with a null
`paymentLinkId`. So the endpoint is not restricted to payment links — the split
is specifically manual versus scheduled.

Reported to Plorea. Read scheduled outcomes from `charges()`.

### ❓ Neither list endpoint has been seen to paginate — probed 2026-09-10

`GET subscriptions` returns `{count, items}` plus an echo of whichever filter
you queried by: `externalId` when listing by external id, `tenantId` when
listing by tenant. `GET subscriptions/{id}/charges` returns
`{subscriptionId, items}`. Neither carries a cursor, page, offset or `hasMore`
key, and no paging parameters have been documented.

A deliberate probe on 2026-09-10 confirmed both shapes and settled nothing
else. Listing by tenant returned `count: 3` against 3 items, and the longest
charge history available — a daily-interval subscription running since
2026-09-04 — held 3 charges. At n=3 `count` agreeing with the number of items
is what you would see under either reading, so nothing distinguishes "this is
everything" from "this is the first page".

The charge endpoint is the more exposed of the two: with no count at all, a
truncated history is indistinguishable from a complete one, and a monthly
subscription accumulates history indefinitely.

The SDK sends no paging parameters, because inventing names for them would be
worse than not sending any, and both methods say so in their docblocks. A
tripwire in `GoldenFixturesTest` asserts `count === count($items)` on the list
fixture, so a future capture where they diverge fails the build.

**To settle it:** a page-crossing dataset cannot be manufactured on demand
without spamming real Adyen test subscriptions. The cheap path is to leave one
daily-interval subscription running — it accumulates roughly 30 charges a
month unattended — and read its charges once the history is long enough to
cross any plausible page size. That is a wait, not a probe.

### ✅ A refund without `X-Environment` hits live credentials — 2026-09-10

`POST payments/refund` sent without the `X-Environment` header routes to
Plorea's **live** Adyen credentials and answers a bare `401` with
`errorType: "security"`. Nothing in the response suggests that environment
routing is what went wrong, so it reads as a bad API key.

`PloreaClient` sets the header on every request from a single place, so no
call made through this SDK can hit it. It is a trap for anyone reproducing a
call with raw `curl` — including debugging a refund against a copied request.
`test_it_requests_a_refund` pins the header on the endpoint where getting it
wrong is most expensive.

---

## Webhooks

### ✅ Deliveries are signed — captured 2026-08-26

No `Authorization` header. Instead:

| Header | Content |
| --- | --- |
| `x-plorea-signature` | base64 HMAC-SHA256 of the raw body |
| `x-plorea-event-id` | `evt_` + 32 hex |
| `x-plorea-event` | the type |

Payload: `{eventId, createdAt, tenantId, type, data: {...}}`.

The signature covers the **raw body only**. The headers are outside it, so
`x-plorea-event-id` and `x-plorea-event` can be rewritten on an otherwise valid
delivery without breaking verification. Deduplicate on the body's `eventId`,
which the SDK exposes as `$event->eventId`.

### ✅ Subscription deliveries — captured 2026-09-04

A **scheduler** charge emits `subscription.charge_succeeded` with
`data: {subscriptionId, chargeId, reference, customerId, shopperReference,
externalId, amount: {value, currency}, pspReference, nextChargeAt,
environment}`. A **manual** `charge()` emits `payment.authorised` with the
ordinary flat payment shape.

Webhook registration is manual and **per tenant**: Plorea delivers to the one
URL you gave them, with no per-environment routing. The tenant these captures
came from had production registered, which is why an earlier staging run saw
nothing at all. If deliveries are missing, confirm which URL is registered
before suspecting your listener.

### 📋 The catalogue is four types — Plorea, 2026-09-09

`payment.authorised`, `payment.failed`, `payment.refunded`,
`subscription.charge_succeeded`. More are planned. Three have now been observed
on the wire; only `payment.refunded` is still routed on the shared envelope
alone.

### ✅ `payment.failed` delivery — captured 2026-09-09

Delivered about two seconds after the payment status flipped, for the refused
payment above.

- The type is `payment.failed` in **both** the `x-plorea-event` header and
  `body.type`. Never `payment.refused`.
- `eventId` is duplicated in the `x-plorea-event-id` header. Use the body copy
  as your idempotency key — only that one is signed.
- The body carries `eventCode: "AUTHORISATION"` with `success: false`,
  mirroring the `webhookEventCode` / `webhookSuccess` pair the status endpoint
  exposes afterwards. No refusal-reason field of any shape.
- `data` is a strict **subset** of the payment status shape: no
  `merchantAccount`, `balanceAccountId`, `store` or `splitsEnabled`, no
  `lastRefund*` / `lastCancel*`, and no `createdAt`/`updatedAt` for the payment
  itself — the top-level `createdAt` is the event's.

Fixture: `webhook-payment-failed.json`.

### 📋 Nothing is emitted for setup, cancel, reactivate, or a failed charge

Confirmed by Plorea 2026-09-09. These transitions are poll-only. A failed
recurring charge never announces itself.

### ✅ Signature verification proven end to end — 2026-09-09

`AuthenticateWebhook` uses `PLOREA_WEBHOOK_SECRET` as the HMAC key
**verbatim** — the characters of the secret, not the bytes they spell.

A real captured delivery was replayed against a live stack running this
middleware. The genuine request returned `200 [accepted]`; the same request with
a **one-byte** payload edit (`NOK` → `EUR`) and the original signature returned
`403`. Separately, `base64(HMAC-SHA256(secret, raw UTF-8 body))` matched the
44-character header exactly while the hex-decoded-key variant did not.

Both halves matter. The first says genuine traffic is accepted; the second says
tampered traffic is rejected. A verifier that only ever sees valid input can
pass every test while doing nothing at all — this one demonstrably rejects.

The check was run by the consuming application, which holds the signing secret;
this repository still has never held a secret, and never should. The result
came back as a match/no-match verdict only — no secret and no signature value
crossed into this repo, and none belongs in a fixture. That is also why there
is no golden test for it: a fixture proving signature verification would have
to contain a real signature over a real body, which is exactly the artefact
this repo refuses to store.

The remaining exposure is not the algorithm but the bytes it runs over: any
middleware that re-encodes the body before `AuthenticateWebhook` sees it will
break verification while the payload still looks valid.

---

## What cannot be reproduced in test

### ❌ A subscription charge that fails

**Verified impossible with the public Adyen test cards, 2026-09-09.** Do not
re-run this investigation; it has been done twice.

The blocked shapes are: the 402 `ChargeFailedException` body, a
`payment_failed` subscription, a populated `failureReason` or non-zero
`retryCount`, and any `subscription.charge_failed` webhook.

Why it cannot be done:

1. Adyen triggers refusals **only** through `paymentMethod.holderName` (e.g.
   `NOT_ENOUGH_BALANCE`, `CARD_EXPIRED`) or
   `additionalData.RequestedTestAcquirerResponseCode`
   ([result codes](https://docs.adyen.com/development-resources/testing/result-codes)).
   Both are fields on the `/payments` request — which, for a stored card, is
   the **setup**. Empirically: the `NOT_ENOUGH_BALANCE` holder name refuses the
   setup verification itself, leaving `storedPaymentMethodId: null` and a
   `failed` method. There is no card to charge later.
2. Custom test cards do not help. Adyen states verbatim: *"If your goal is to
   test failure scenarios, there is no need to generate your own test card.
   Instead, refer to Testing result codes and refusal reasons"*
   ([create test cards](https://docs.adyen.com/development-resources/testing/create-test-cards)).
   Ranges minted in the Customer Area carry no decline behaviour.
3. Every card on Adyen's published list is symmetric-approve, and every one
   carries a **future** fixed expiry (03/2030, one 12/2030). There is no
   expired card to store-then-decline with.
4. Amount-based refusal triggering does not apply — that mechanism is
   in-person/terminal only, not ecommerce.
5. Plorea's scheduler charges the stored method server-side with no passthrough
   for either lever. The SDK controls only the amount.

**Unblocking requires Plorea**, either by provisioning a test card that stores
successfully and declines on a later charge, or by exposing
`RequestedTestAcquirerResponseCode` passthrough on
`POST subscriptions/{id}/charge`. Both have been requested.

Consequently: the fake's 402 stub and the `payment_failed` status are modelled
from Plorea's documentation, not from an observation. Write dunning code that
tolerates a shape slightly different from what the SDK models — branch on
`status`, and do not require `failureReason` to be present.

### ✅ A refused one-off payment — closed 2026-09-09

The payment-link path **is** reproducible, and was. Adyen refuses the card
`4000 0000 0000 0259` after a full 3DS2 challenge — not because it is an Adyen
test card (it is not; it is in Stripe's range and appears nowhere on Adyen's
published list) but precisely because Adyen does not recognise it. The hosted
pay page exposes only card number, expiry and CVC iframes, so the `holderName`
lever is unreachable there too.

Capture: `payment-status-refused.json`.
