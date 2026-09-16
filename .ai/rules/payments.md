# Payments

Field-tested behaviour of payment links, statuses, refunds and the pay page.
Do not "fix" these to match what the API documentation implies.

## Amounts

**Minor units (øre).** `Amount::nok(450000)` is 4 500,00 kr. There is no
major-unit constructor, on purpose.

## Statuses

- open = `created` | `pending` | `active`
- paid = `authorised` | `paid` (test payments settle on `authorised`)
- **A refused payment reports `failed`, NOT `refused`** (captured 2026-09-09).
  `refused` is a string the API never returns; earlier docs of ours wrongly
  used it, so any `is('refused')` written from them was dead code.
- `refund_requested` / `cancel_requested` appear immediately after
  `payments/refund` / `payments/cancel` and persist until the provider settles.
- `expired` has **never** been observed live. Judge expiry from the pay-page
  endpoint's computed `expired` flag or the stored `expiresAt`, never the
  status string.

The status endpoint returns HTTP 200 for open never-paid links and 404 for
unknown references.

`webhookEventCode` / `webhookSuccess` / `lastWebhookAt` describe Plorea's
**inbound** Adyen webhook — not webhooks sent to your app. On a refusal they
carry `AUTHORISATION` + `false`, which is the only decline signal: there is no
`failureReason`-style field on the payment status shape.

## Refunds

**Settlement takes HOURS, not minutes** — >11h observed 2026-09-09 in test;
Adyen batches test refunds. `refund_requested` means *accepted*, never
pending-and-doomed.

Size polling loops in hours. An hour-long timeout reports a healthy refund as
failed, and an app that then reverses its books or tells the customer does real
damage.

There is **no per-refund status field**. Pre-settlement the only signal is the
payment's top-level `status == refund_requested` plus the `lastRefund*` group.
Post-settlement status and keys are **UNOBSERVED**.

**`refundPspReference` is response-only.** It exists on the
`POST payments/refund` response and never on `payments/status`, whose
`lastRefundRequestPspReference` identifies the *request*, not the refund.
Persist the `Refund` DTO at refund time — provider support asks for that
identifier and no later poll can recover it.

Status-body keys verified identical across three independent captures
(2026-09-09): 29 keys, all read by `PaymentStatus`. **`lastRefundStatus` does
not exist** — a report that it did turned out to be a `jq` null-read of a
missing key.

## No idempotency on duplicate references

Creating twice gives two live, payable links. `firstOrCreate()` implements the
reuse/suffix strategy; an already-paid reference must fail loudly with
`PaymentAlreadyPaidException`. The check-then-create is not atomic — consuming
apps should wrap it in a per-reference lock if double submits are possible.

**The walk runs the whole suffix chain, not just up to the first reusable
link.** A superseded base reference stays `created` forever — nothing flips it
when the customer pays the `-1` link instead — so stopping at the first open
link would hand back a payable link for an invoice that was already settled
under a later suffix. Every suffix is probed until a 404 ends the chain; the
first reusable link found is held and returned only once the walk proves no
later suffix was paid. Cost is one extra status request per call, and
`PaymentAlreadyPaidException` can now carry the status of a *suffixed*
reference rather than only the base one.

## `merchantOrgNr` is contractually required

Stated by Plorea 2026-08-30. Always the invoice issuer's (client's) org nr,
never the platform's own. `store` / `balanceAccountId` are resolved
automatically and must not be sent.

The API does **not** enforce it (verified: a link without one still creates and
even gets a session), so **the SDK enforces it in `create()`** — a link that
cannot be paid out is worse than a loud failure.

`merchantOrgNr` / `merchantName` are **not** echoed on the create response.
They appear only on `pay/{id}`, which also gains a populated `partnerSplits`
once a merchant is attached.

First payment for a new org nr auto-starts KYC; payout is released on approval
(1–5 business days). The earlier "Invalid Store" / 422-at-session regression was
a Plorea-side KYC bug, fixed 2026-08-30 (verified the same day: a retest link
on a fresh org nr created, and a session issued for it).

## `platform` goes on every request — 2026-09-15

Stated by Plorea. `platform` is **internal logging and reporting
only** — no functional effect on any endpoint. Send it on all calls, not just
payment-link creation.

`PloreaClient::post()` / `patch()` stamp it from `plorea.platform`, which has
**no default and must never gain one**. This is a public package: a default
would make every app that installs it report itself under a name it did not
choose, and Plorea's reporting would attribute that traffic to the wrong
integrator. Unset, the field is omitted entirely; each consuming app sets
`PLOREA_PLATFORM` to its own identifier. A value already in the payload wins,
which is what `PendingPaymentLink::platform()` relies on. `FakeClient` mirrors
this so a payload assertion that passes under the fake describes a real
request.

**Every verb, including reads.** A `GET` has no body, so it rides in the
query string: `payments/status/FIN-1?platform=...`. Plorea documented
`platform` as a body field and reads were deliberately left out at first; that
was reversed on 2026-09-15 by Einar's decision to send it everywhere, since the
field is reporting-only and a read attributed to nobody is a gap in exactly the
reporting it exists for.

The cost is real and was accepted knowingly: **every read the SDK makes has a
different URL than it used to.** A consuming app that pins exact `GET` URLs in
its tests will break on upgrade. Stub on the path with a trailing wildcard
(`payments/status/FIN-1?*`), not on the bare URL. This repo's own suite had to
be rewritten that way — see `PlatformFieldTest` and the `?*` keys across
`tests/Feature/`.

One trap in that rewrite: a stub key must match every verb that hits the URL.
`subscriptions/sub_1` is both a `GET` (with a query) and a `PATCH` (without
one), so it needs `sub_1*`, not `sub_1?*`. And never widen a key past the
segment — `INV-1*` also swallows `INV-1-1`, which silently breaks the
firstOrCreate suffix walk.

It was **not** the cause of the refund/cancel `401`. That was an Adyen
credentials problem in Plorea's test environment, which they fixed; the same
outage took `pay.plorea.no` down (back up, verified 2026-09-15). Do not
re-litigate the platform A/B.

## Capture is automatic — there is no capture API

Stated by Plorea 2026-09-15. Capture runs through AmendoPOS, typically minutes
after authorization. **A manual capture endpoint is on their roadmap and does
not exist today**, so do not add a `capture()` method or model a pending-capture
state. `authorised` is the terminal success state an integrator can observe —
which is already how `isPaid()` treats it.

## Embedded checkout is blocked on a client key

Verified 2026-09-09. `payByLink()->session()` (`POST payments/session`) is
callable directly and returns a live Adyen session — `pay.plorea.no` is just a
Drop-in mounted on one.

But **`$session->clientKey` comes back `null`**, and lifting the key from the
hosted page's JS does not help: Adyen scopes client keys to an allowed-origins
list nobody else is on. The Drop-in mounts fine and *then* fails the
`/sessions/{id}/setup` preflight with CORS — late, and looking nothing like a
credential problem.

Needs Plorea to whitelist origins or issue a key. **No SDK change**: the DTO
exposes `clientKey` because the response has the field, not because it is
usable.

**Update 2026-09-15 — supported, gated on credentials.** Plorea confirmed the
flow is supported: on request they issue the integrator an Adyen client key
(test and live) and whitelist that integrator's origins, including
`http://localhost:*` for development. The key is issued out of band and lives
in the consuming app's environment — **never in this repo, and never a config
default here**. Nothing in the SDK changes until an origin-whitelisted session
has been mounted successfully and the shape is observed.

Native mobile is the same endpoint with one extra field: `channel` of `iOS` or
`Android` on `POST payments/session` makes the response carry the `clientKey`
the native Adyen SDK needs, and requires the app's bundle id / package name
whitelisted in Plorea's Adyen Customer Area. Today's working path needs none of
it: a WebView on the hosted pay page is `channel: "Web"` and already works. The
SDK sends no `channel` today — do not add one until an origin-whitelisted
session proves the shape.

**Update 2026-09-16 — built ahead of observation, by Einar's decision.** The
"wait for the shape" rule above was overruled: native apps and web Drop-in are
being built now, and the SDK had to be able to ask. What exists:

- `Enums\Channel` (`Web` / `iOS` / `Android`), sent as `channel` by
  `payByLink()->session(..., channel:)` and
  `paymentMethods()->setup()->channel()->session()`. **Omitted when not
  given** — a request without it is byte-identical to before, and Plorea
  treats it as `Web`. Never default it.
- `plorea.adyen_client_key` (`PLOREA_ADYEN_CLIENT_KEY`), **no default**, same reasoning as
  `platform`. Both session DTOs take the response's `clientKey` first and fall
  back to the configured one; `environment` falls back to
  `plorea.environment`. `raw` still holds the response untouched, so the
  fallback never hides what Plorea sent.
- Both session DTOs are `JsonSerializable` to `toCheckout()` —
  `sessionId`, `sessionData`, `clientKey`, `environment`. `raw` carries
  tenant, shopper reference and customer id; a consuming app's
  `response()->json($session)` must never ship those to a browser or an app.
  Do not widen `toCheckout()` without that in mind.

Still **UNOBSERVED** — replace these with captures (and golden fixtures) as
they land: a native-channel response; `channel` on
`payment-methods/setup/session` (asked, not answered); a custom-scheme
`returnUrl`; a live `environment` value; a web Drop-in mounted end to end. The
fake returns a key only for native channels, on both endpoints — the
setup-session half of that is an assumption, and says so.

## A refund without `X-Environment` hits LIVE credentials — 2026-09-10

`POST payments/refund` sent without the `X-Environment` header routes to
Plorea's **live** Adyen credentials and answers a bare `401` with
`errorType: "security"`. Nothing in the body hints that environment routing is
the problem, so it reads as a bad API key and sends you hunting the wrong bug.

`PloreaClient::request()` sets the header on every request from one place, so
no call through the SDK can hit it — **do not move that header to individual
resources.** The trap is for raw `curl` reproductions of an SDK request.
`test_it_requests_a_refund` pins it.
