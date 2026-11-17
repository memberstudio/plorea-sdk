# Error handling

Every exception extends `MemberFlow\Plorea\Exceptions\PloreaException`, so one
`catch` covers the package.

| Exception | Thrown when |
| --- | --- |
| `ValidationException` | 400 — invalid request data, or an operation the current state does not allow |
| `PaymentMethodInUseException` | 400 on `paymentMethods()->delete()` — subscriptions still use the method; `activeSubscriptionIds()` lists them. Extends `ValidationException` |
| `AuthenticationException` | 401 / 403 — invalid or missing API key, a tenant mismatch, or a request that reached the wrong environment |
| `ChargeFailedException` | 402 — a subscription charge was declined by the acquirer |
| `NotFoundException` | 404 — unknown reference or id |
| `NextChargeInPaidPeriodException` | 409 with `paidUntil` on a subscription update — the new `nextChargeAt` is inside a paid period; `paidUntil()` returns the period end. Send `allowEarlyCharge()` to charge anyway. Stated by Plorea 2026-10-14. A 409 without `paidUntil` is a plain `RequestException` |
| `ServerException` | 5xx — Plorea-side error |
| `ConnectionException` | The API could not be reached |
| `PaymentAlreadyPaidException` | `firstOrCreate()` found the reference already paid |
| `PloreaException` | Everything else, including the SDK's own guards |

`ValidationException`, `AuthenticationException`, `ChargeFailedException`,
`NotFoundException`, `NextChargeInPaidPeriodException` and `ServerException` all extend `RequestException`, which
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

## A 401 or 403 that is not about the API key

Since 2026-10-13 the API key sets the environment (`plr_test_` or
`plr_live_`). An `X-Environment` header that does not match the key returns
`403`, which the SDK throws as `AuthenticationException`. An ID from the other
environment returns `404` (`NotFoundException`). A live key with
`PLOREA_ENVIRONMENT` unset sends `test` and gets `403` on every call. Check
the variable before you rotate the key (stated by Plorea 2026-10-13; the
cross-environment `404` observed in production the same day).

Before 2026-10-13, `X-Environment` alone told Plorea which set of Adyen
credentials to use. Omitting it on `POST payments/refund` routed the call to
**live** credentials and came back `401` with `errorType: "security"`.
Nothing in the body suggested the environment was what went wrong, so it read
as a bad key (observed 2026-09-10). Plorea states that a missing header now
uses the key's environment. The refund case has not been re-tested.

A missing header cannot happen through the SDK. `PloreaClient` sets the header on every
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
| `refund()` | Only with the *same* `idempotencyKey`. Without one, every call is a new refund |
| `cancel()` | **Unknown** — not confirmed to deduplicate. Read `status()` and check the cancel fields before a retry |
| `subscriptions()->charge()` | Only with the *same* `idempotencyKey` **and** the same amount |
| `create()` a subscription | **No** |

`charge()` and `refund()` take an `idempotencyKey:` argument. The SDK sends it
as the `Idempotency-Key` header. It refuses an empty key, or a key longer than
128 characters, before it sends anything.

```php
Plorea::subscriptions()->charge($id, amount: Amount::nok(4900), idempotencyKey: "{$id}-2026-11");
Plorea::payments()->refund($reference, "{$reference}-refund-1", idempotencyKey: "{$reference}-refund-1");
```

What the key does, per the OpenAPI spec (0.36.0 and later; **not observed** by
this SDK):

- **Charge.** A repeat with the same key and amount returns the first answer
  with `replayed: true` and charges nothing. The same key with another amount
  or currency gives 409 "Idempotency-Key reused with different parameters". A
  repeat while the first call still runs gives 409 "A charge with this
  Idempotency-Key is still in flight". Both 409s carry `chargeId`, and neither
  charges. A replayed decline keeps its `refusalReason`. Without a key, the
  subscription's period is the key, so a manual charge and the scheduler's run
  for the same period are one charge.
- **Without a key, the amount is part of the default key** (stated by Plorea,
  2026-11-17). Two charges with different amounts in one period are two
  charges. With your own key, another amount gives the 409 above and charges
  nothing. Do not record a 409 as money collected.
- **Refund.** The key is scoped to the payment refunded. Without a key, every
  call gets a fresh one, so a double click refunds twice.

Keep a key stable across retries of the same operation, and store it with the
operation. A key is namespaced per tenant.

For payment links, use `firstOrCreate()` rather than retrying. For anything
else non-idempotent, fail and reconcile by reading state back.

A timeout is not a failure — it is an unknown. Re-read state before deciding.

## Never leak the key

The SDK strips transfer stats from failed responses so exception output cannot
carry the `Authorization` header, and `RequestSent` / `ResponseReceived` carry
no headers at all. A connection failure is redacted the same way: Guzzle's
exception keeps the outbound request, so `ConnectionException` rewrites the
header in the wrapped chain before it reaches you — and drops the chain
entirely if it cannot. That is why `$e->getPrevious()` is occasionally null on
a transport failure. Keep that property when you log:

```php
report($e);                      // fine
Log::error($e->getMessage());    // fine
Log::error(json_encode($request->headers->all()));  // never
```

See also: [Testing](testing.md) · [Request logging](request-logging.md)
