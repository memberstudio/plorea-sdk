# Changelog

All notable changes to `memberflow/plorea` will be documented in this file.

## Unreleased

### Added

- **Native checkout: `Enums\Channel`.** `payByLink()->session()` takes an
  optional `$channel`, and `paymentMethods()->setup()` gains `channel()` for its
  Drop-in session. `Channel::IOS` / `Channel::Android` open a session for
  Adyen's native SDKs. Nothing is sent unless a channel is given, so existing
  calls are unchanged. The native response shape is stated by Plorea and not yet
  observed; `channel` on card setup is unconfirmed.
- **`plorea.adyen_client_key` (`PLOREA_ADYEN_CLIENT_KEY`)** for the Adyen client key
  Plorea issues for embedded Drop-in. `PaymentSession::$clientKey` and the new
  `PaymentMethodSession::$clientKey` use the response's key first (native
  channels) and fall back to it. `environment` falls back to
  `plorea.environment`.
- **`toCheckout()` on both session DTOs**, returning `sessionId`,
  `sessionData`, `clientKey` and `environment`.
- Guides: [Embedded and native checkout](docs/checkout.md) and
  [Going live](docs/going-live.md). Boost skills: `plorea-checkout` and
  `plorea-go-live`.

### Changed

- **`PaymentSession` and `PaymentMethodSession` serialize to `toCheckout()`.**
  They implement `JsonSerializable`, so `response()->json($session)` sends the
  four checkout fields instead of every public property, including `raw` with
  tenant, shopper reference and customer id. Code that relied on the old JSON
  shape should read the properties directly.
- The fake returns a client key from both session endpoints for native
  channels only.

## v0.2.1 - 2026-09-10

### Added

- **Webhook events carry the delivery's identity.** `PaymentStatusUpdated`,
  `SubscriptionChargeSucceeded` and `WebhookReceived` now expose `$eventId`
  and `$type`, so consumers can deduplicate and branch without reaching into
  `$payload`. Both are appended to the constructors with null defaults, so
  existing positional construction is unaffected.
- **Deduplicate on `$event->eventId`, not on the `x-plorea-event-id` header.**
  The signature is computed over the raw body and covers nothing else, which
  makes the headers the one part of an otherwise valid delivery an attacker
  can rewrite: a replay carrying a genuine body, a genuine signature and a
  fresh header value verifies successfully and would be processed twice by any
  app keyed on the header. The events therefore read the signed body copy, and
  a test posts a signed body alongside a contradictory header to pin it. The
  previous documentation recommended the header, which was the unsafe half of
  a true statement — the id is duplicated there, but only one copy is signed.
  No middleware change; verification was never affected.
- **`subscriptions()->needingAttention()`** — the polling half of dunning.
  Returns subscriptions for an external id that report the `payment_failed`
  status **or** whose `nextChargeAt` is more than `$graceMinutes` (default 60)
  in the past. A failed scheduler charge emits no webhook, so this is the only
  way to notice one.
- **`Subscription::isOverdue(int $graceMinutes = 60, ?DateTimeInterface $now = null)`**
  — whether a scheduled charge is late. Plorea's scheduler charges within
  seconds of `nextChargeAt` and moves the date forward as it does, so a date
  still in the past means the cycle did not complete. A canceled subscription
  is never overdue: it keeps whatever `nextChargeAt` it had when scheduling
  stopped. This is derived entirely from fields captured on the wire, which is
  why `needingAttention()` leans on it rather than on the never-observed
  `payment_failed` status — if Plorea's documented failure shape turns out to
  differ, the overdue check still fires.
- `SubscriptionCharge::is()` and `isAuthorised()`. A freshly created charge
  reports `charge_created`, not `authorised` — read it back from `charges()`
  to find out how it landed.

### Changed

- `forExternalId()` and `charges()` no longer claim to return *all* records.
  Neither endpoint carries a cursor, page, offset or `hasMore` key, no paging
  parameters have been documented, and every capture is too small to tell
  whether the response is complete: one subscription, two charges. The list
  envelope's `count` agrees with the number of items returned at that size,
  which settles nothing. `charges()` is the more exposed of the two, having no
  count at all, so a truncated history would be indistinguishable from a
  complete one — and a monthly subscription accumulates history indefinitely.
  The SDK sends no paging parameters, because inventing names for them would
  be worse than sending none. `GoldenFixturesTest` now asserts
  `count === count($items)` on the list fixture and pins both envelopes' key
  sets, so a future capture that paginates fails the build rather than
  silently truncating. Verify large result sets against your own records until
  this is settled. A probe on 2026-09-10 confirmed both envelopes and settled
  nothing else: listing by tenant returned `count: 3` against 3 items, and the
  longest charge history available held 3 charges, which agrees under either
  reading. It also showed the list envelope echoes whichever filter you
  queried by, `externalId` or `tenantId`.

### Documented

- **A refund sent without the `X-Environment` header routes to Plorea's live
  Adyen credentials** and answers a bare `401` with `errorType: "security"`,
  carrying nothing that points at environment routing — so it reads as a bad
  API key (observed 2026-09-10). No call made through this SDK can hit it:
  `PloreaClient` sets the header on every request from one place, and
  `test_it_requests_a_refund` now pins that for the endpoint where getting it
  wrong is most expensive. The trap is for raw `curl` reproductions, which is
  what you reach for when a 401 has you doubting your key.

## v0.2.0 - 2026-09-09

### Documentation

- Full documentation now lives in `docs/`, split into guides (getting started,
  payments, payment methods, subscriptions, webhooks, testing, error handling,
  request logging), a complete `docs/api-reference.md` covering every public
  method, DTO property, enum and event, and `docs/api-behaviour.md` — a dated
  record of what has actually been observed against the live Plorea test
  environment, what was only stated by Plorea, and what is known to be
  impossible to reproduce in test. The README is now an overview that links
  into it.

### Fixed

- **A refused payment reports `status: "failed"`, not `"refused"`.** Captured
  2026-09-09 from a real declined authorisation. Previous documentation and
  the fake's stub examples used `refused`, a string the API never returns, so
  any `$status->is('refused')` written from those examples was dead code.
  `isPaid()` and `isOpen()` were already correct. The decline itself is
  carried by `webhookEventCode: "AUTHORISATION"` with `webhookSuccess: false`;
  there is no `failureReason`-style field on the payment status shape.

### Added

- `subscription.charge_succeeded` webhooks now dispatch a dedicated
  `SubscriptionChargeSucceeded` event carrying `subscriptionId`, `chargeId`,
  `reference` and `externalId`. It deliberately does not also raise
  `PaymentStatusUpdated` — firing both would book the same charge twice.
- Trial support: `->trialUntil()` creates the subscription `trialing` with
  `nextChargeAt` equal to `trialEndsAt`, and `isTrialing()` distinguishes it
  from `isActive()`.
- `RecurringType::CardOnFile` and `RecurringType::UnscheduledCardOnFile` are
  verified against the live API and echoed back on setup.
- Golden fixtures: anonymised captures of real API responses in
  `tests/Fixtures/`, asserted through the SDK's DTOs by
  `tests/Feature/GoldenFixturesTest.php`. Covers subscriptions, payment
  methods, trials, subscription webhooks, the create/update/reactivate error
  shapes, and the refused payment above.
- **Webhook signature verification is proven end to end** (2026-09-09). A real
  captured delivery was replayed against a live stack running
  `AuthenticateWebhook`: the genuine request was accepted, and the same request
  with a one-byte payload edit (`NOK` → `EUR`) carrying the original signature
  was rejected with 403. The convention was confirmed alongside it —
  `base64(HMAC-SHA256(secret, raw UTF-8 body))` matched the header, the
  hex-decoded-key variant did not. The middleware needs no change; this was
  previously the package's one entirely unverified security-relevant behaviour,
  and it is now verified in both directions rather than only against valid
  input. No secret or
  signature value entered this repository, and none ever should, which is also
  why this cannot be covered by a golden fixture.
- Embedded checkout is documented on `payByLink()->session()`: the session
  endpoint is callable directly and returns a live Adyen session, but
  `$session->clientKey` comes back **null** and Adyen scopes client keys to an
  allowed-origins list you will not be on. The Drop-in mounts and then fails a
  CORS preflight late, looking nothing like a credential problem. Embedding
  needs Plorea to whitelist your origins or issue you a key — ask before you
  build. No SDK change; the blocker is entirely credential-side.
- The refund PSP reference is documented as response-only: `refundPspReference`
  exists on the `POST payments/refund` response and never on
  `payments()->status()`, whose `lastRefundRequestPspReference` identifies the
  *request* rather than the refund. Persist the `Refund` DTO — that identifier
  cannot be recovered from a later poll, and it is what provider support asks
  for. There is no per-refund status field at all.
- Refund settlement latency is documented: a refund stayed `refund_requested`
  for **more than 11 hours** before `payment.refunded` fired (test environment,
  2026-09-09). Polling loops must be sized in hours — an hour-long timeout
  reports a healthy refund as failed.
- A real `payment.failed` webhook delivery is now captured as
  `tests/Fixtures/webhook-payment-failed.json` and asserted end to end through
  the signed webhook route. Three of Plorea's four catalogue types have now
  been observed on the wire; only `payment.refunded` is still routed on the
  shared envelope alone. The delivery confirms the type string is
  `payment.failed` in both the `x-plorea-event` header and `body.type`, that
  `eventId` is duplicated in `x-plorea-event-id` so deliveries can be
  deduplicated without parsing the body, and that `data` is a strict subset of
  the payment status shape carrying no refusal reason of any kind.

### Changed

- Webhook documentation now matches Plorea's official catalogue (confirmed
  2026-09-09): `payment.authorised`, `payment.failed`, `payment.refunded` →
  `PaymentStatusUpdated`; `subscription.charge_succeeded` →
  `SubscriptionChargeSucceeded`. Nothing is emitted for card setup,
  cancellation, reactivation, or a **failed** scheduler charge — those are
  poll-only, so dunning must poll.
- Scheduler-created charge references (`{subId}-{chgId}`) do not resolve
  through `payments()->status()`: 403 "Tenant mismatch" on 2026-09-04, 404
  "Payment not found" on retest 2026-09-09. Manual charges do resolve. Read
  scheduled outcomes from `charges()`. Reported to Plorea.
- `reactivate()` no longer double-bills a cancel-then-reactivate (fixed by
  Plorea, verified 2026-09-09), and reactivating an already-active
  subscription now returns 400. Guarding on an elapsed `accessEndsAt` is
  still recommended while production is unobserved.
- Documented that `failureReason` is null even for a genuine refusal, and that
  Plorea's validation messages are static templates — a request missing two
  fields lists all five — so they must never be parsed.

### Known limitations

- A **failing subscription charge cannot be reproduced** with Adyen's public
  test cards (verified 2026-09-08 and 2026-09-09). Adyen triggers refusals
  only through `paymentMethod.holderName` or
  `additionalData.RequestedTestAcquirerResponseCode`, both of which ride on
  the `/payments` request — i.e. card setup — where they refuse the
  verification and leave no stored card to charge. Consequently the 402
  `ChargeFailedException` body, the `payment_failed` subscription status, a
  populated `failureReason` / `retryCount`, and any `subscription.charge_failed`
  webhook are modelled from Plorea's documentation rather than observed.
  Write dunning code that tolerates a slightly different shape.

### Earlier in this cycle

- `merchantOrgNr` is now required when creating payment links — Plorea needs
  it to route the payout (it must be the invoice issuer's org number, never
  the platform's own). `create()` throws `PloreaException` when it is
  missing, since the API silently accepts the link and fails only at
  payment time. `->store()` is deprecated: Plorea resolves the store from
  the org number automatically.

## v0.1.0

- Initial release: payment links, payment status, refunds, cancellations,
  payment method setup (hosted + Drop-in), subscriptions (create, update,
  list by external ID, manual charges, charge history, cancel, reactivate),
  webhook scaffolding with events, and a full testing fake
  (`Plorea::fake()`).
- `firstOrCreate()` reuses an open payment link for a reference or creates
  one, guarding against duplicate live links. Reuse requires matching
  amount, currency, tenant, and environment, and verifies expiry against
  the pay-page endpoint. Already-paid references throw
  `PaymentAlreadyPaidException`.
- Webhook authentication matches Plorea's production behavior: the
  merchant-generated shared secret is compared with `hash_equals` and the
  route fails closed until `PLOREA_WEBHOOK_SECRET` is configured.
- `Plorea::fake()` mirrors the real API for status lookups: references
  with a created link report an open status, unknown references 404.
- Failed responses attached to `RequestException` have their transfer stats
  stripped so exception output never carries the `Authorization` header,
  and malformed response dates parse to `null` instead of throwing.
- CI: tests across PHP 8.4/8.5 × Laravel 12/13, Pint, PHPStan level 8,
  Rector, gitleaks secret scanning, and dependency audits.
