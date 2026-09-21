---
name: plorea-go-live
description: "Use this skill when preparing a Plorea Payments integration (memberflow/plorea) for production: switching from test to live, live API keys and PLOREA_ENVIRONMENT, registering the production webhook and its signing secret, merchant KYC before launch, retry and idempotency settings, monitoring Plorea requests, live Adyen client keys and whitelisting for embedded or native checkout, or running a first real transaction. Also use when reviewing a Plorea integration for launch readiness."
license: MIT
metadata:
  author: memberflow
---
# Going live with Plorea

Walk this list and report each item as done, missing, or not applicable. Most items fail silently.

## Credentials
- Live API key from Plorea, only in production env; rotate it if it has ever been in chat, a ticket or a log.
- `PLOREA_ENVIRONMENT=live` — it **defaults to `test`**, so unset means production traffic hits test.
- `PLOREA_TENANT_ID` for production; `PLOREA_PLATFORM` set to your own identifier (no default).

## Webhooks
- Production URL registered with Plorea (manual; ask for a separate URL per environment) and the production secret in `PLOREA_WEBHOOK_SECRET` — confirm with Plorea whether it differs from test.
- Plorea does **not** redeliver a failed webhook. Persist each delivery before processing; a `500` loses the event.
- `PLOREA_WEBHOOK_VERIFY` true/unset. `false` lets anyone post fake events.
- Webhook path excluded from CSRF, or every delivery is a 419.
- Listeners queued, deduplicating on `$event->eventId` (not the header), re-fetching state rather than trusting payloads.
- A scheduled backstop polls open payments, pending card setups and `subscriptions()->needingAttention()` — card setup, cancel, reactivate and failed scheduler charges emit nothing.

## Merchants
- `merchantOrgNr` is always the invoice issuer's, never your own.
- The first live payment for a new org number starts KYC; payout is held until approval (1–5 business days). Tell merchants.
- Subscriptions for a client company carry `->merchant()` too; KYC then starts on the first charge. There is no KYC API — to onboard a company before launch, Plorea suggests a payment link carrying its org number, sent to yourself.

## Money-handling code
- `PLOREA_RETRY_TIMES=0` unless every retried write is idempotent — retried link creation or charges double-bill.
- `firstOrCreate()` inside `Cache::lock()` per reference; handle `PaymentAlreadyPaidException`.
- Refund polling sized in **hours**; a timeout never reverses books or tells the customer it failed. Persist the `Refund` DTO.
- Booking is idempotent on the reference.

## Embedded / native checkout (if used)
- Live `PLOREA_ADYEN_CLIENT_KEY`; production origins (including customer-owned domains) and production app identifiers whitelisted for the live key.
- Universal links / App Links verified on the production domain; the return page completes `redirectResult`.
- CSP allows Adyen's **live** hosts. Apple Pay domain verification per domain, if wallets are shown.
- App Store / Play review: real-world services only, otherwise in-app purchase applies.
- Session endpoints return `response()->json($session)`, never `raw`.

## Observability
- Alert on `ResponseReceived` with `status >= 400` and on `ConnectionException`.
- Alert on stuck states: links past `expiresAt`, setups `pending_setup` past `expiresAt`.
- Confirm your own HTTP logging does not record the `Authorization` header.

## Launch test
One low-value live payment per shipped surface (hosted, web Drop-in, iOS, Android) for a KYC-approved merchant → webhook verified and booked once → refund, allowing hours to settle → if selling subscriptions, store a card and let a charge run.
