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

### ✅ Subscription deliveries — captured 2026-09-04

A **scheduler** charge emits `subscription.charge_succeeded` with
`data: {subscriptionId, chargeId, reference, customerId, shopperReference,
externalId, amount: {value, currency}, pspReference, nextChargeAt,
environment}`. A **manual** `charge()` emits `payment.authorised` with the
ordinary flat payment shape.

Plorea's registered webhook URL points at **production**, not staging — which
is why an earlier staging run saw nothing at all.

### 📋 The catalogue is four types — Plorea, 2026-09-09

`payment.authorised`, `payment.failed`, `payment.refunded`,
`subscription.charge_succeeded`. More are planned. Only the first and last have
been observed on the wire; the other two are routed on the shared envelope.

### 📋 Nothing is emitted for setup, cancel, reactivate, or a failed charge

Confirmed by Plorea 2026-09-09. These transitions are poll-only. A failed
recurring charge never announces itself.

### ❓ Signature verification is UNPROVEN

`AuthenticateWebhook` uses `PLOREA_WEBHOOK_SECRET` as the HMAC key
**verbatim** — the characters of the hex string, not the bytes they spell.

No check has ever been run against a real signature and its matching secret,
because the two have never been held at the same time: the secret was never
stored anywhere, and the captured deliveries cannot be verified against the
fixtures because the tenant id in them is redacted.

If Plorea insists deliveries are correctly signed and the route rejects them,
try `hex2bin($secret)` as the key. **Get one worked example — raw body, a
throwaway secret, and the resulting `X-Plorea-Signature` — and verify it before
trusting the middleware end to end.**

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
