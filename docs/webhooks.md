# Webhooks

> **The one rule:** a webhook is a *ping*, not a fact. Every listener in this
> document re-fetches authoritative state from the API. Payloads are
> attacker-controllable if verification is ever off, delivery is best-effort,
> and the same event can arrive twice.

## Registration

Registration is **manual, per tenant**. Hand Plorea your webhook URL
(`https://your-app.example/plorea/webhook`) through a secure channel and obtain
the signing secret from them.

The package registers `POST /plorea/webhook` automatically:

```dotenv
PLOREA_WEBHOOKS_ENABLED=true
PLOREA_WEBHOOK_PATH=plorea/webhook
PLOREA_WEBHOOK_SECRET=...
```

Exclude the path from CSRF verification if your app applies it globally:

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->validateCsrfTokens(except: ['plorea/webhook']);
})
```

Add a throttle through `plorea.webhooks.middleware` (e.g. `'throttle:60,1'`) to
rate-limit secret-guessing.

## Authentication

Real deliveries captured from Plorea's test environment are **signed**, not
authenticated with an echoed header. Each request carries:

| Header | Content |
| --- | --- |
| `X-Plorea-Signature` | base64-encoded HMAC-SHA256 of the **raw request body** |
| `x-plorea-event-id` | `evt_` + 32 hex characters |
| `x-plorea-event` | the event type, e.g. `payment.authorised` |

There is **no** `Authorization` header on a real delivery.

The middleware verifies the signature against `PLOREA_WEBHOOK_SECRET` with
`hash_equals`. Requests carrying no signature header fall back to comparing the
`Authorization` header against the secret verbatim (optionally `Bearer`-prefixed),
for registrations that use an echoed shared secret instead. **It fails closed**:
with no secret configured, every request is rejected.

`PLOREA_WEBHOOK_SECRET` is used as the HMAC key **verbatim** — the characters
of the secret as Plorea gives them to you, not the bytes they spell.

This was proven end to end on 2026-09-09 by replaying a real captured delivery
against a live stack: the genuine request was accepted, and the same request
with a one-byte payload edit and the original signature was rejected with 403.
So if the route rejects a delivery you believe is genuine, the secret or the raw
body is wrong — not the encoding.

> [!WARNING]
> Verify against the **raw** body. Any middleware that re-encodes the request
> before this one — a JSON normaliser, a body-rewriting proxy, a trailing
> newline — changes the bytes and breaks the HMAC while leaving the payload
> looking perfectly valid.

### Waiting on the secret

```dotenv
PLOREA_WEBHOOK_VERIFY=false   # staging/test only
```

This accepts deliveries without authentication so the rest of the flow can be
exercised. **Never in production** — without it anyone who knows the URL can
post fake webhooks. The re-fetch pattern below is what keeps that from being
catastrophic, which is another reason to follow it.

## The event catalogue

Confirmed by Plorea on 2026-09-09. More types are planned, so treat the list as
open.

| Plorea type | SDK event | Observed on the wire |
| --- | --- | --- |
| `payment.authorised` | `PaymentStatusUpdated` | Yes |
| `payment.failed` | `PaymentStatusUpdated` | Yes |
| `payment.refunded` | `PaymentStatusUpdated` | No — routed on the shared envelope |
| `subscription.charge_succeeded` | `SubscriptionChargeSucceeded` | Yes |

The type string is what you would expect from the status endpoint, not from
the English: a refusal is `payment.failed`, never `payment.refused`.

Every delivery — including types not in this table — also dispatches the
catch-all `WebhookReceived`. The package will not invent a typed event for a
payload shape nobody has seen; branch on `type` in `WebhookReceived` for
anything new.

### What is NOT sent

Plorea confirmed on 2026-09-09 that **nothing** is emitted for:

- card setup, success or failure
- subscription cancellation
- subscription reactivation
- a **failed** scheduler charge

These transitions are **poll-only**. Read them from `paymentMethods()->find()`,
`subscriptions()->find()` and `payments()->status()`. A failed recurring charge
in particular will never announce itself — if you need dunning, you must poll.

## Payload shape

```json
{
  "eventId": "evt_...",
  "createdAt": "2026-08-26T09:12:44.000Z",
  "tenantId": "your-tenant",
  "type": "payment.authorised",
  "data": {
    "reference": "FIN-2026-00123",
    "status": "authorised",
    "eventCode": "AUTHORISATION",
    "success": true
  }
}
```

See `tests/Fixtures/webhook-payment-authorised.json` and
`webhook-payment-failed.json` for full captures.

`data` is a strict **subset** of the payment status shape — no
`merchantAccount`, `balanceAccountId`, `store` or `splitsEnabled`, no
`lastRefund*` / `lastCancel*`, and no `createdAt` or `updatedAt` for the
payment itself. The top-level `createdAt` is the *event's*. On a failure the
body carries `eventCode: "AUTHORISATION"` with `success: false` and no
refusal-reason field of any shape, mirroring the status endpoint exactly.

`eventId` appears both in the body and in the `x-plorea-event-id` header. Every
SDK event exposes it as `$event->eventId`, alongside `$event->type`:

```php
if (Cache::add("plorea:webhook:{$event->eventId}", true, now()->addDay()) === false) {
    return;  // already handled
}
```

> [!WARNING]
> Deduplicate on `$event->eventId`, not on the header. The signature is
> computed over the raw body and covers nothing else, so the headers are the
> one part of an otherwise valid delivery an attacker can rewrite freely. A
> replay carrying a genuine body, a genuine signature and a fresh
> `x-plorea-event-id` passes verification and would be processed twice by any
> app keyed on the header. The body copy is signed; use it.

The controller extracts the reference and status defensively — nested `data.*`
first, then flat fallbacks — but the extracted values are only used to *address*
the re-fetch, never to decide anything.

## Payments

```php
use MemberFlow\Plorea\Events\PaymentStatusUpdated;
use MemberFlow\Plorea\Facades\Plorea;

Event::listen(PaymentStatusUpdated::class, function (PaymentStatusUpdated $event) {
    $status = Plorea::payments()->status($event->reference);   // authoritative

    if ($status->isPaid()) {
        // Book it — idempotently. The webhook, a status poll and the
        // customer's return page can all race on the same reference.
    }
});
```

`$event->status` and `$event->payload` are available, but treat them as hints.
Compare `$status->amount` against your local expectation before booking money.

`$event->eventId` and `$event->type` carry the delivery's own identity — the
catalogue type (`payment.authorised`, `payment.failed`, `payment.refunded`) and
the id to deduplicate on. Both are read from the signed body.

## Subscriptions

Captured from real deliveries on 2026-09-04:

| Trigger | Type | SDK event |
| --- | --- | --- |
| Scheduler charges the card | `subscription.charge_succeeded` | `SubscriptionChargeSucceeded` |
| Manual `charge()` | `payment.authorised` | `PaymentStatusUpdated` |

A manual charge produces an ordinary flat payment payload, which is why it
routes to the payment event.

```php
use MemberFlow\Plorea\Events\SubscriptionChargeSucceeded;

Event::listen(SubscriptionChargeSucceeded::class, function (SubscriptionChargeSucceeded $event) {
    $event->subscriptionId;
    $event->chargeId;
    $event->reference;   // "{subId}-{chgId}"
    $event->externalId;  // your own entity id
    $event->eventId;     // deduplicate on this

    $subscription = Plorea::subscriptions()->find($event->subscriptionId);

    // Extend access idempotently — ->status, ->nextChargeAt, ->accessEndsAt
});
```

`SubscriptionChargeSucceeded` deliberately does **not** also raise
`PaymentStatusUpdated`. Firing both would have every listener book the same
charge twice, and the `{subId}-{chgId}` reference is not resolvable through the
payment status endpoint for scheduler charges anyway
([details](subscriptions.md#looking-a-charge-up-as-a-payment)). Read the charge
from `charges()`.

## Everything else

```php
use MemberFlow\Plorea\Events\WebhookReceived;

Event::listen(WebhookReceived::class, function (WebhookReceived $event) {
    match ($event->payload['type'] ?? null) {
        'subscription.some_future_type' => /* branch and re-fetch */,
        default => null,
    };
});
```

## Responses and retries

The controller always answers `200 [accepted]`, including for unparseable
payloads. Plorea states (2026-09-21) that a failed delivery is **not
redelivered**, so answering `500` does not buy you a second attempt — it loses
the event. Accept the delivery, store it, and retry your own processing from
what you stored.

Queue anything slow. The route should answer fast; do the work in a job.

## Do not rely on webhooks alone

Run a scheduled job that refreshes state directly:

```php
// Open payment links.
Plorea::payments()->status($invoice->reference);

// Active subscriptions.
Plorea::subscriptions()->forExternalId($workspace->externalId, status: 'active');
```

This is not defensive over-engineering. Delivery is best-effort, and the
failure cases that matter most — a declined recurring charge — emit no webhook
at all.

## Testing your listeners

```php
$response = $this->postJson('/plorea/webhook', [
    'eventId' => 'evt_test',
    'type' => 'payment.authorised',
    'data' => ['reference' => 'ref-1', 'status' => 'authorised'],
], ['X-Plorea-Signature' => base64_encode(hash_hmac('sha256', $body, config('plorea.webhooks.secret'), true))]);
```

Or set `PLOREA_WEBHOOK_VERIFY=false` in `phpunit.xml` and post without a
signature. The fixtures in `tests/Fixtures/webhook-*.json` are real captured
deliveries and make good test bodies.

See also: [Payments](payments.md) · [Subscriptions](subscriptions.md) · [Verified API behaviour](api-behaviour.md)
