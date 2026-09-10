# Webhooks

## Deliveries are SIGNED — captured 2026-08-26, test env

Deliveries carry **no** `Authorization` header. Instead:

| Header | Content |
| --- | --- |
| `x-plorea-signature` | base64 HMAC-SHA256 of the raw body |
| `x-plorea-event-id` | `evt_` + 32 hex |
| `x-plorea-event` | the event type, e.g. `payment.authorised` |

The signing key comes from Plorea — it is **not** the API key or the tenant id.
`AuthenticateWebhook` verifies the signature when present and falls back to an
Authorization-echo comparison otherwise. Compare with `hash_equals`; fail
closed without a secret.

Payload shape (verified):
`{eventId, createdAt, tenantId, type, data: {reference, status, eventCode, success, …}}`.
See `tests/Fixtures/webhook-payment-authorised.json`.

`data` is a strict **subset** of the payment status shape — no
`merchantAccount` / `balanceAccountId` / `store`, no `lastRefund*` /
`lastCancel*`, and the only `createdAt` is the event's own.

`eventId` is duplicated in the `x-plorea-event-id` header, but **the signature
covers the raw body only** — the headers sit outside it, so they are the one
part of an otherwise valid delivery an attacker can rewrite. A replay with a
genuine body, a genuine signature and a fresh header value verifies fine and
would be double-processed by anything keyed on the header.

All three webhook events therefore expose `$event->eventId` and `$event->type`
read from the **body**. Deduplicate on those. Do not "simplify" the controller
to read the headers instead; `test_it_reads_the_event_id_from_the_signed_body_not_the_header`
pins it.

Still treat webhooks as pings and re-fetch `payments/status/{reference}`.

## Signature verification is PROVEN end-to-end — 2026-09-09

A real captured delivery was replayed against a live stack running
`AuthenticateWebhook`: the genuine request returned **200**, and the same
request with a one-byte payload edit (`NOK`→`EUR`) carrying the original
signature returned **403**.

The convention was confirmed alongside it: `base64(HMAC-SHA256(secret, raw UTF-8
body))` matched the header; the `hex2bin($secret)`-as-key variant did **not**.

**The middleware is correct as written — do not "fix" the key handling.**

The check ran in the consuming app, which holds the secret. Only a match/no-match
verdict crossed over; see [security.md](security.md) for why there is no golden
fixture for this and why that is deliberate.

Residual risk is the **bytes, not the algorithm**: any middleware that
re-encodes the body before the verifier sees it breaks the HMAC while the
payload still looks perfectly valid.

## Catalogue — 4 types (Plorea, 2026-09-09), more planned

| Type | SDK event | Captured |
| --- | --- | --- |
| `payment.authorised` | `PaymentStatusUpdated` | Yes |
| `payment.failed` | `PaymentStatusUpdated` | Yes (2026-09-09) |
| `payment.refunded` | `PaymentStatusUpdated` | No — routed on the shared envelope |
| `subscription.charge_succeeded` | `SubscriptionChargeSucceeded` | Yes |

Everything else reaches consumers through `WebhookReceived` **only**. The SDK
does not invent typed events for shapes nobody has seen.

`payment.failed` carries the type string in both the header and `body.type` —
**never `payment.refused`** — with `eventCode: AUTHORISATION` + `success: false`
and no refusal-reason field of any shape.

## Subscription webhooks — CAPTURED 2026-09-04

Plorea's registered URL points at **production**, not staging — that is why an
earlier staging run saw nothing at all. Production logs held exactly 3
deliveries for the lifecycle run.

- A **scheduler** charge emits `subscription.charge_succeeded` with
  `data: {subscriptionId, chargeId, reference, customerId, shopperReference, externalId, amount: {value, currency}, pspReference, nextChargeAt, environment}`.
- A **manual** `charge()` emits `payment.authorised` with the ordinary flat
  payment `data` shape (n=1 for the manual case).

`WebhookController` dispatches `SubscriptionChargeSucceeded` for the former and
**deliberately does not also dispatch `PaymentStatusUpdated`** — firing both
would have every listener book the same charge twice, and that
`{subId}-{chgId}` reference does not resolve through the payment status
endpoint anyway (see [subscriptions.md](subscriptions.md)).

Fixtures: `webhook-subscription-charge-succeeded.json`,
`webhook-payment-authorised-subscription-charge.json`.

## No webhook exists for setup / PM activation, cancel or reactivate

A full lifecycle emitted only the two types above. **Do NOT add events for
them** — those API calls return the new state synchronously. A **failed**
scheduler charge also never announces itself, so dunning must poll.
