# Testing

`Plorea::fake()` swaps the HTTP client for an in-memory fake. No request leaves
your test suite, every endpoint has a sensible default response, and every call
is recorded for assertions.

```php
use MemberFlow\Plorea\Data\Amount;
use MemberFlow\Plorea\Facades\Plorea;

Plorea::fake();

$link = Plorea::payments()
    ->link('ref-1', 'Product', Amount::nok(50000), 'https://example.test/return')
    ->merchant(orgNr: '912650774')
    ->create();

Plorea::assertSent('payments/link');
Plorea::assertSent(fn ($request) => $request->input('amount') === 50000);
Plorea::assertSentCount(1);
```

Call `Plorea::fake()` in `setUp()` or at the top of the test — anything
resolved before it still holds the real client.

## Assertions

```php
Plorea::assertSent('payments/link');                       // path pattern
Plorea::assertSent('POST payments/link');                  // method + path
Plorea::assertSent('subscriptions/*/charge');              // wildcards
Plorea::assertSent(fn ($request) => $request->input('reference') === 'ref-1');
Plorea::assertNotSent('payments/refund');
Plorea::assertNothingSent();
Plorea::assertSentCount(2);

Plorea::recorded();  // Collection<RecordedRequest>
```

A `RecordedRequest` exposes `->method`, `->path`, `->data` and
`->input($key, $default)`. `data` is the payload for POST/PATCH and the query
string for GET.

```php
Plorea::recorded()->first()->input('merchantOrgNr');  // '912650774'
```

## Stubbing

Pass an array of `pattern => response`. Responses may be arrays, callables, or
exceptions to throw.

```php
use MemberFlow\Plorea\Exceptions\{ChargeFailedException, NotFoundException};

Plorea::fake([
    'payments/status/*' => ['reference' => 'ref-1', 'status' => 'authorised'],
    'POST payments/link' => fn ($request) => ['paymentLinkId' => 'pl_1', 'url' => 'https://pay.test/1'],
    'subscriptions/*/charge' => new ChargeFailedException('Charge failed: Refused', 402),
    'payment-methods/*' => new NotFoundException('Payment method not found', 404),
]);
```

Patterns match the request path with `Str::is()` wildcards, optionally prefixed
with a method. The first matching stub wins; anything unmatched falls through
to the defaults.

Add stubs later with `Plorea::fake()->stub([...])`.

## Default responses

With no stub, the fake answers these routes:

| Route | Default |
| --- | --- |
| `POST payments/link` | A created link echoing your reference, tenant and amount |
| `POST payments/session` | A payment session |
| `GET pay/{id}` | A pay page, `expired: false` |
| `GET payments/status/{ref}` | See below |
| `POST payments/refund` | `refund_requested` |
| `POST payments/cancel` | `cancel_requested` |
| `POST payment-methods/setup` | A `pending_setup` method |
| `POST payment-methods/setup/session` | A Drop-in session |
| `GET payment-methods/{id}` | An `active` method |
| `POST subscriptions` | An active subscription echoing your payload |
| `GET subscriptions` | A list |
| `GET|PATCH subscriptions/{id}` | The subscription |
| `POST subscriptions/{id}/charge` | `charge_created` |
| `GET subscriptions/{id}/charges` | A charge history |
| `POST subscriptions/{id}/cancel` | A cancellation |
| `POST subscriptions/{id}/reactivate` | The subscription, `active` |

Anything else throws `PloreaException` naming the route, so a typo or a new
endpoint fails loudly instead of silently returning nothing.

Defaults echo what you asked for rather than a fixed body: a subscription with
a future `trialEndsAt` comes back `trialing`, a filtered subscription list
applies your `tenantId` / `status` query to the item it returns, and a manual
charge reports the amount and VAT you sent. Stub the route when you need a
state the defaults would not produce.

### Status lookups mirror the real API

This is the one piece of stateful behaviour, and it exists so `firstOrCreate()`
works out of the box:

- A reference the fake has **seen a link created for** reports an open
  (`active`) status echoing that link's amount and tenant.
- Any **other** reference throws `NotFoundException`, exactly like a live 404.
- A creation whose stub **threw** does not count as seen. The request is still
  visible to `assertSent()`, but the reference stays a 404, so a failed create
  cannot look like a successful one.

```php
Plorea::fake();

$pending = Plorea::payments()->link('ref-1', 'P', Amount::nok(50000), 'https://x.test')->merchant(orgNr: '912650774');
$pending->create();
$again = $pending->firstOrCreate();   // reuses the open link — no second create

Plorea::assertSentCount(4);           // create, status, pay-page expiry check, status for ref-1-1
```

The fourth request is the suffix walk: `firstOrCreate()` probes one reference
past the reusable link to prove no later suffix was paid. See
[Payments](payments.md).

Stub `payments/status/*` to simulate a refusal, or any other state:

```php
Plorea::fake(['payments/status/*' => ['reference' => 'ref-1', 'status' => 'authorised']]);

$this->expectException(PaymentAlreadyPaidException::class);
$pending->firstOrCreate();
```

## Testing webhooks

Post to the route directly. See
[Webhooks → Testing your listeners](webhooks.md#testing-your-listeners).
The files in `tests/Fixtures/webhook-*.json` are real captured deliveries and
make the most realistic bodies.

## Golden fixtures

`tests/Fixtures/` holds anonymised captures of **real** Plorea responses, and
`tests/Feature/GoldenFixturesTest.php` runs each one through the SDK's DTOs.
That is what keeps the models honest: if Plorea changes a shape, or if a
hand-written assumption drifts from reality, those tests fail.

When you capture a new real response, anonymise it (tenant id, PSP references,
shopper references, card last 4, emails) and add it there rather than writing a
fixture by hand.

> [!IMPORTANT]
> Real credentials never go in this repository — not in tests, not in
> fixtures, not in comments. That includes API keys, webhook signing secrets
> and real signatures.

## Preventing stray requests

The package's own suite calls `Http::preventStrayRequests()`. Doing the same in
your app turns any un-faked Plorea call into a loud failure instead of a real
HTTP request.

See also: [Error handling](errors.md) · [Verified API behaviour](api-behaviour.md)
