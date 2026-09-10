# Error handling

Every exception extends `MemberFlow\Plorea\Exceptions\PloreaException`, so one
`catch` covers the package.

| Exception | Thrown when |
| --- | --- |
| `ValidationException` | 400 — invalid request data, or an operation the current state does not allow |
| `AuthenticationException` | 401 / 403 — invalid or missing API key, a tenant mismatch, or a request that reached the wrong environment |
| `ChargeFailedException` | 402 — a subscription charge was declined by the acquirer |
| `NotFoundException` | 404 — unknown reference or id |
| `ServerException` | 5xx — Plorea-side error |
| `ConnectionException` | The API could not be reached |
| `PaymentAlreadyPaidException` | `firstOrCreate()` found the reference already paid |
| `PloreaException` | Everything else, including the SDK's own guards |

`ValidationException`, `AuthenticationException`, `ChargeFailedException`,
`NotFoundException` and `ServerException` all extend `RequestException`, which
exposes the response:

```php
try {
    Plorea::subscriptions()->charge($id);
} catch (ChargeFailedException $e) {
    $e->status;              // 402
    $e->response?->json();   // the raw error body
    $e->getMessage();
}
```

## A 401 that is not about the API key

Requests carry an `X-Environment` header that tells Plorea which set of Adyen
credentials to use. Omit it on `POST payments/refund` and the call routes to
**live** credentials and comes back `401` with `errorType: "security"` —
nothing in the body suggests the environment is what went wrong, so it reads
as a bad key (observed 2026-09-10).

This cannot happen through the SDK. `PloreaClient` sets the header on every
request from one place. It is a trap for raw `curl` reproductions, which is
exactly what you reach for when a 401 has you doubting your key. Check the
header before you rotate anything.

## Reading the message

Plorea's error bodies are not consistently shaped, so the message falls back
through `error`, then `message`, then the raw body.

> [!WARNING]
> Validation messages are **static templates**. A request missing two required
> fields answers with a list of all five. Log them; show them to a developer;
> never parse them, and never render them to a customer as a list of what to
> fix.

## 400 vs 402 — a real distinction

```php
try {
    Plorea::subscriptions()->charge($id);
} catch (ChargeFailedException $e) {
    // 402 — the card was declined. Start dunning; the subscription is fine.
} catch (ValidationException $e) {
    // 400 — the subscription is not chargeable at all: cancelled, or its
    // payment method is not active. Your app sent a request it should not have.
}
```

Both a not-chargeable subscription and a not-active payment method return
**400**. A 402 is a card decline and nothing else.

## Not found

`NotFoundException` is a normal control-flow answer, not an error, in the
places where "does this exist?" is the question:

```php
try {
    $status = Plorea::payments()->status($reference);
} catch (NotFoundException) {
    $status = null;  // never created, or a typo in the reference
}
```

An open, never-paid link returns 200. Only genuinely unknown references 404.

## Already paid

```php
use MemberFlow\Plorea\Exceptions\PaymentAlreadyPaidException;

try {
    $link = $pending->firstOrCreate();
} catch (PaymentAlreadyPaidException $e) {
    $e->status;  // the settled PaymentStatus — pspReference, amount, ...
}
```

This one is deliberately loud. Silently creating a fresh link for a settled
invoice makes it payable twice; there is no safe default, so the SDK refuses to
pick one.

## Retries and idempotency

`PLOREA_RETRY_TIMES` retries connection errors and 5xx responses. Default is
**0**, on purpose:

| Operation | Safe to retry |
| --- | --- |
| `status()`, `find()`, `charges()`, `forExternalId()` | Yes — reads |
| `POST payments/link` | **No** — no idempotency; a retry can create a second live link |
| `refund()`, `cancel()` | Yes, with the *same* `modificationReference` |
| `subscriptions()->charge()` | **No** — no idempotency key exists |
| `create()` a subscription | **No** |

For payment links, use `firstOrCreate()` rather than retrying. For anything
else non-idempotent, fail and reconcile by reading state back.

A timeout is not a failure — it is an unknown. Re-read state before deciding.

## Never leak the key

The SDK strips transfer stats from failed responses so exception output cannot
carry the `Authorization` header, and `RequestSent` / `ResponseReceived` carry
no headers at all. Keep that property when you log:

```php
report($e);                      // fine
Log::error($e->getMessage());    // fine
Log::error(json_encode($request->headers->all()));  // never
```

See also: [Testing](testing.md) · [Request logging](request-logging.md)
