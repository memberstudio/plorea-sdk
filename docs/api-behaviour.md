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

What the top-level status becomes *after* settlement is still unobserved. A
refund and a cancellation requested on 2026-09-19 were both still
`refund_requested` / `cancel_requested` more than 24 hours later. Plorea states
(2026-09-21) that this is expected, because the provider processes
modifications asynchronously, and that the final statuses are `refunded` and
`cancelled`. Book on the request; do not wait for the final status.

### ✅ `merchantOrgNr` is not echoed back on create — 2026-09-07

The create response omits `merchantOrgNr` and `merchantName` entirely. They
appear only on `GET pay/{id}`, which also gains a populated `partnerSplits`
once a merchant is attached.

### 📋 `merchantOrgNr` is contractually required — Plorea, 2026-08-30

The API does **not** enforce it: a link created without one is accepted and
even issues a session (verified). The SDK enforces it in `create()` anyway,
because a link that cannot be paid out is worse than a loud failure. An earlier
"Invalid Store" / 422 at session time was resolved by Plorea on 2026-08-30 and
verified the same day.

### 📋 `platform` belongs on every request — Plorea, 2026-09-15

Internal logging and reporting on Plorea's side, with no functional effect on
any endpoint. The SDK adds it to every `POST` and `PATCH` body from
`plorea.platform`: in the body on `POST` and `PATCH`, and in the query string
on `GET`, which has no body to carry it. Reads therefore have a different URL
than they did before — `payments/status/FIN-1?platform=...` — which matters if
you assert exact URLs anywhere.

It was also **not** the cause of the `401` on refund and cancel. That was a
configuration issue in the test environment, since resolved by Plorea (see
"The refund/cancel `401` is gone" below).

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

### ✅ `channel` is accepted and ignored; a session carries no key — 2026-09-17, morning

**Every line of this entry was superseded later the same day — read on.** It is
kept because it is the before-picture that the rollout below is a change from.

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

So the native path Plorea described (2026-09-15) is **not live in test** as of
this probe. The SDK still sends `channel`, since it is ignored rather than
rejected, and the configured key is what a native app must use until Plorea's
side lands.

Unobserved at this point — all three were settled the same evening, below: a
client key that works from a whitelisted origin, a Drop-in mounted end to end,
and a live `environment` value.

### ✅ Native support, partly rolled out — later on 2026-09-17

Plorea announced native support on both session endpoints the same day.
Probed again afterwards:

- **`payment-methods/setup/session`** validates `channel` —
  `{"error": "channel must be one of Web, iOS, Android"}` for anything else,
  lowercase `ios` included — and echoes it in the response (`Web` when none is
  sent). It returns a `clientKey` for **every** channel, `Web` included.
- **`payments/session`** is unchanged: any `channel` is accepted and
  `clientKey` is `null`.
- **Both endpoints now accept a custom-scheme `returnUrl`.**
- The key card setup returns differs from the one issued out of band, and
  both are valid. Replaying Adyen's `/sessions/{id}/setup` preflight against a
  payment session: with **no `Origin` header** — what a native SDK sends —
  both keys answer `200`. With a browser `Origin`, only the returned key is
  accepted from whitelisted origins (`200`); the other answers `403`
  everywhere. So the origin allow-list is per key and applies to browsers
  only, which is why a native app works with a key whose origins are unset.
  A session's `clientKey` is the safest choice; the SDK already prefers it.
- A wildcard origin covers subdomains at any depth, but not the bare domain.
  `localhost` is not whitelisted, at any port.

### ✅ Native Drop-in, verified on device — 2026-09-17, evening

An iOS integration (Adyen's Drop-in/Sessions SDK 5.20.2, `Environment.test`)
mounted both session types with `channel: "iOS"` and a custom-scheme
`returnUrl`:

- **Card setup** used the key in the response. The payment method reached
  `active`.
- **A payment** carries no key, so the app used the configured one. Drop-in
  mounted, a test card authorised, and Plorea reported `authorised` a minute
  later. The falling back this SDK does is therefore what makes native payment
  work today, and it confirms from the app side that Adyen checks neither
  origin nor bundle id on this path: no error mentioning origin, client key or
  `403` appeared anywhere.
- **3-D Secure arrived as a browser redirect**, not as Adyen's native 3DS2
  challenge: the challenge opened Adyen's test simulator in an in-app browser
  and returned through the custom scheme, which the app handled. Plorea's
  session does not ask for native 3DS2
  (`authenticationData.threeDSRequestData.nativeThreeDS`), so a native
  integration must handle the redirect and its return URL regardless of
  channel.

### ✅ Web Drop-in and 3DS, verified end to end — 2026-09-17, late

- **A browser Drop-in authorises from a whitelisted origin.** Adyen Web 5.71.0
  mounted on a payment session from a whitelisted staging subdomain, using the
  key a setup session returned, was accepted at `/sessions/{id}/setup` with the
  browser's `Origin` present, offered `scheme` and `googlepay`, and reported
  `Authorised`. Plorea moved the payment to `authorised` and the
  `AUTHORISATION` webhook arrived.
- **Authorisation survives a 3DS challenge.** The redirect returns through the
  app's custom scheme and Plorea reports `authorised`.
- **A refused challenge does reach Plorea**, as
  `webhookEventCode: "AUTHORISATION"` with `webhookSuccess: false` — the same
  shape as any other decline, still with no reason field.

### ✅ An unsubmitted session never reaches a terminal state — 2026-09-17

A session that is created and then abandoned — the customer closes Drop-in, or
never opens it — still reads `created` more than an hour later, with no webhook
fields set. Like payment links, which never report `expired`, a session has no
observed terminal state. **Do not poll a session waiting for it to fail.** Time
the attempt out yourself and open a new session for the next try.

### ✅ `payments/session` now matches card setup — 2026-09-19

Plorea announced the fix on 2026-09-18; probed the day after
(`plorea:probe --app-return-url`), it supersedes the `payments/session` lines
of the 2026-09-17 entries above:

- **`channel` is validated**, exactly like card setup: a lowercase `ios`, an
  unknown string and an integer all answer `400` with
  `{"error": "channel must be one of Web, iOS, Android"}`.
- **`channel` is echoed**, and is `Web` when none is sent. The response is now
  `sessionId`, `sessionData`, `environment`, `channel`, `clientKey`.
- **A `clientKey` comes back for every channel**, `Web` included, with https
  and custom-scheme return URLs alike. It is the same key card setup returns,
  so `plorea.adyen_client_key` is now a pure fallback on both endpoints.
- **The bare domain is whitelisted.** Replaying Adyen's `/sessions/{id}/setup`
  preflight with the returned key: the bare production domain and its
  subdomains answer `200`; `localhost` and an unrelated origin still answer
  `403`; no `Origin` answers `200`.

### 📋 What Plorea said on 2026-09-18, not yet observed

- **The key the sessions return is the web Drop-in key**, and the one to use.
  A client key is tied to the origins registered on it. The hosted pay page
  mounts its Drop-in with a key of its own (observed 2026-09-19), registered
  for the pay page's origin, so that key answers `403` from any other origin.
  Configure only a key Plorea issued for your origins — or none, and use the
  one the session returns.
- **App ids are not enforced.** Adyen does no native origin check on app
  calls, so unregistered bundle ids / package names block nothing (matches the
  2026-09-17 device test). Plorea registers them formally before go-live.
- **Native 3DS2 is coming**: `nativeThreeDS: "preferred"` on sessions with
  channel `iOS` / `Android`, in an upcoming deploy. Until it is observed, a native app
  must keep handling the redirect.
- **`refusalReason` is coming** on `GET payments/status/{reference}`, same
  deploy. Until it is observed, `failed` still has no reason — do not model the
  field.
- **Customers' own domains: route the payment step through your own
  whitelisted subdomain.** The wildcard covers every subdomain; no API for
  registering single origins is planned.

### ✅ The refund/cancel `401` is gone — 2026-09-19

Plorea reported it resolved on 2026-09-18. Re-tested the day after:

- **Refund**: a 10 kr link paid on the hosted pay page reached `authorised`
  within a minute; `POST payments/refund` answered `refund_requested` with a
  `refundPspReference`.
- **Cancel**: the payment from the original report (authorised 2026-09-10,
  `401` on every attempt until 2026-09-11) accepted a cancel and reads
  `cancel_requested` with a `lastCancelRequestPspReference`.
- On this refund, `lastRefundRequestPspReference` on the status body equals the
  `refundPspReference` from the refund response. One sample — keep persisting
  the `Refund` DTO.

Still unobserved: a live `environment` value, a live client key, and any
Android channel on a device.

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

### ✅ `merchantOrgNr` on subscriptions — 2026-09-21

`POST subscriptions` accepts `merchantOrgNr`, `merchantName` and
`merchantEmail`. The create response echoes **`merchantOrgNr` only**, as its
last key, for both an `active` and a `trialing` create
(`subscription-created-merchant.json`). This differs from payment links, whose
create response echoes nothing. An organisation number that is not nine digits
returns `400` — "Invalid merchantOrgNr — must be 9 digits". The first scheduled
charge on such a subscription authorised normally.

On that date, `GET subscriptions/{id}`, `GET subscriptions` and the items from
`GET subscriptions/{id}/charges` did **not** return the field. That changed the
next day — see below.

### ✅ `merchantOrgNr` on subscription reads — 2026-09-22

Plorea added the field to reads. Captured on a trial subscription created with
a merchant: `GET subscriptions/{id}` returns `merchantOrgNr`
(`subscription-merchant.json`), and so does each item of `GET subscriptions`
(`subscription-list-merchant.json`). `GET subscriptions/{id}/charges` carries
it once, on the envelope — `{subscriptionId, merchantOrgNr, items}` — not on
each charge (`subscription-charges-merchant.json`). A subscription created
without a merchant returns the key as `null`. The name and email are still not
returned anywhere.

### 📋 What `merchantOrgNr` does on a subscription — Plorea, 2026-09-21

Every charge inherits the company. KYC for a company Plorea has not seen
starts on the first charge, and settlement splits apply from that charge. The
organisation number is included on charge webhooks. A subscription without the
field behaves as before. None of this is observable from the API responses.

A stored payment method is tied to the shopper reference, not to a company:
the same method can be charged for different organisation numbers under one
tenant.

There is no API for starting or reading KYC. To onboard a company ahead of its
first real charge, Plorea suggests creating a payment link that carries its
organisation number and sending it to yourself; that starts KYC the ordinary
way.

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

Settling it takes time rather than effort: a page-crossing dataset cannot be
manufactured on demand without spamming real Adyen test subscriptions. A
daily-interval subscription left running accumulates roughly 30 charges a
month unattended, and its charges can be read once the history is long enough
to cross any plausible page size.

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

Webhook registration is manual and **per tenant**. Until 2026-09-21 Plorea
delivered to the one URL you gave them, with no per-environment routing, so a
tenant registered against production sent nothing at all to a staging run —
that is the shape of this trap, and it has been walked into. If deliveries are
missing, confirm which URL is registered before suspecting your listener.

### 📋 Routing, signing and redelivery — Plorea, 2026-09-21

- Plorea can register one URL per environment (test and live) on request.
  Ask for it; it is not the default. `data.environment` remains a useful
  second filter.
- The same signing secret may be used for both environments. Do not assume
  the test and live secrets differ — ask.
- **A failed delivery is not retried.** There is no automatic redelivery, so a
  `500` from your endpoint loses the event. Polling is not a nicety.

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

The check was run inside a consuming application, which is where the signing
secret lives; this repository has never held a secret, and never should. The result
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

**Verified impossible with the public Adyen test cards, 2026-09-09** — twice,
independently. The reasoning below is why, so you do not have to repeat it.

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
