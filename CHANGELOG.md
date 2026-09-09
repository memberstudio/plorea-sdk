# Changelog

All notable changes to `memberflow/plorea` will be documented in this file.

## Unreleased

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
- **The webhook signature convention is confirmed** (2026-09-09). Checked
  against a real production delivery by the consuming application, which holds
  the signing secret: `base64(HMAC-SHA256(secret, raw UTF-8 body))` matched the
  header exactly, and the hex-decoded-key variant did not. `AuthenticateWebhook`
  is therefore correct as written and needs no change — this was previously the
  package's one entirely unverified security-relevant behaviour. No secret or
  signature value entered this repository, and none ever should, which is also
  why this cannot be covered by a golden fixture.
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
