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
a Plorea-side KYC bug, fixed 2026-08-30 (verified:
`SDK-KYC-RETEST-20260830104706` created + session OK).

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
