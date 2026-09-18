# Plorea SDK for Laravel

[![Tests](https://github.com/memberstudio/plorea-sdk/actions/workflows/tests.yml/badge.svg)](https://github.com/memberstudio/plorea-sdk/actions/workflows/tests.yml)
[![Static Analysis](https://github.com/memberstudio/plorea-sdk/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/memberstudio/plorea-sdk/actions/workflows/static-analysis.yml)
[![Latest Stable Version](https://img.shields.io/packagist/v/memberflow/plorea)](https://packagist.org/packages/memberflow/plorea)
[![License](https://img.shields.io/packagist/l/memberflow/plorea)](LICENSE.md)

A Laravel SDK for the [Plorea Payments API](https://docs.plorea.no) — payment
links, refunds, stored cards, subscriptions and webhooks, with a fluent API and
first-class testing support.

Plorea abstracts the underlying payment provider (Adyen) behind a simple API:
you create a payment link, your customer pays on `pay.plorea.no`, and Plorea
notifies you by webhook.

```php
use MemberFlow\Plorea\Data\Amount;
use MemberFlow\Plorea\Facades\Plorea;

$link = Plorea::payments()
    ->link('FIN-2026-00123', 'Faktura FIN-2026-00123', Amount::nok(450000), 'https://app.example/paid')
    ->payerEmail('kunde@eksempel.no')
    ->merchant(orgNr: '912650774', name: 'Techify AS')
    ->firstOrCreate();

return redirect($link->url);
```

## Installation

```bash
composer require memberflow/plorea
```

```dotenv
PLOREA_API_KEY=plr_test_...
PLOREA_ENVIRONMENT=test        # test | live
PLOREA_TENANT_ID=your-tenant
PLOREA_WEBHOOK_SECRET=         # the webhook route rejects everything until this is set
PLOREA_PLATFORM=your-platform  # optional — sent with every request for Plorea's internal reporting
```

Requires PHP 8.4+ and Laravel 12 or 13. The service provider is auto-discovered.

## Documentation

Full documentation lives in [`docs/`](docs/README.md).

| Guide | |
| --- | --- |
| [Getting started](docs/getting-started.md) | Installation, the full configuration reference, the facade |
| [Payments](docs/payments.md) | Payment links, `firstOrCreate`, status, refunds, cancellations |
| [Payment methods](docs/payment-methods.md) | Storing cards — hosted redirect and Adyen Drop-in |
| [Embedded and native checkout](docs/checkout.md) | Drop-in on your domain or in an iOS / Android app |
| [Going live](docs/going-live.md) | The production checklist |
| [Subscriptions](docs/subscriptions.md) | Create, trials, update, cancel, reactivate, charges, dunning |
| [Webhooks](docs/webhooks.md) | Signature verification, the event catalogue, what is poll-only |
| [Testing](docs/testing.md) | `Plorea::fake()`, stubs, assertions |
| [Error handling](docs/errors.md) | Exceptions, status mapping, retries and idempotency |
| [Request logging](docs/request-logging.md) | Building an audit trail from SDK events |
| [API reference](docs/api-reference.md) | Every method, DTO property, enum and event |
| [Verified API behaviour](docs/api-behaviour.md) | What has actually been observed against the live API — and what has not |

## What you need to know before writing code

Six behaviours account for most of the surprises. Each links to the detail.

**Amounts are minor units.** `Amount::nok(450000)` is 4 500,00 kr. There is no
major-unit constructor, on purpose.

**`merchantOrgNr` is required**, and it is always the invoice issuer's — your
client's — organisation number, never your platform's. Plorea accepts a link
without it and fails only at payout time, so the SDK refuses to create one.
→ [Payments](docs/payments.md#the-merchant-is-required)

**There is no idempotency on references.** Creating twice gives you two live,
payable links. Use `firstOrCreate()`, which reuses an open link, supersedes a
dead one, and throws `PaymentAlreadyPaidException` for a settled reference.
→ [Payments](docs/payments.md#reuse-or-create-firstorcreate)

**Webhooks are pings, not truth.** Re-fetch authoritative state in every
listener. Delivery is best-effort, and several important transitions — card
setup, cancellation, reactivation, and a **failed** recurring charge — emit no
webhook at all. If you need dunning, poll `subscriptions()->needingAttention()`.
Deduplicate on `$event->eventId`, never on the `x-plorea-event-id` header — the
signature covers the body alone.
→ [Webhooks](docs/webhooks.md#what-is-not-sent)

**There is nothing to capture.** Plorea settles automatically a few minutes
after authorization and exposes no manual capture endpoint, so `authorised`
means done — which is what `isPaid()` already returns true for. Do not build a
pending-capture state to wait in.
→ [Payments](docs/payments.md#capture-is-automatic)

**Status strings do not always say what you would guess.** A refused payment
reports `failed`, not `refused`. Subscriptions use the US spelling `canceled`.
`expired` is never reported — judge expiry from `expiresAt`. Use the typed
helpers (`isPaid()`, `isActive()`, `is('...')`) rather than comparing strings.
→ [Verified API behaviour](docs/api-behaviour.md)

## Checkout surfaces

The hosted pay page is the supported path, and a WebView on it is all a mobile
app needs today. Two other surfaces mount Adyen's Drop-in yourself, and both
need a client key from Plorea:

| Surface | Status |
| --- | --- |
| Hosted pay page (`$link->url`) | Works. Web and WebView alike |
| Adyen Drop-in on your own domain | Works. Set `PLOREA_ADYEN_CLIENT_KEY`; Plorea issues it and whitelists your origins on request |
| Native iOS / Android Adyen SDK | Works. `session(..., channel: Channel::IOS)`, plus a return URL your app handles |

```php
return response()->json(Plorea::payByLink()->session($link->id, $returnUrl, Channel::Android));
// {"sessionId": ..., "sessionData": ..., "clientKey": ..., "environment": ...} — and nothing else
```

→ [Embedded and native checkout](docs/checkout.md) · [Going live](docs/going-live.md)

## Testing

```php
Plorea::fake();

// ... exercise your code ...

Plorea::assertSent('payments/link');
Plorea::assertSent(fn ($request) => $request->input('amount') === 50000);
```

No HTTP leaves your suite, every endpoint has a sensible default, and status
lookups mirror the real API closely enough that `firstOrCreate()` works out of
the box. → [Testing](docs/testing.md)

## Verified against the live API

The claims in these docs are not read off Plorea's API documentation. Anonymised
captures of **real** responses live in `tests/Fixtures/` and are run through the
SDK's DTOs by `tests/Feature/GoldenFixturesTest.php`, so a shape change breaks
the build.

[Verified API behaviour](docs/api-behaviour.md) records what has been observed,
when, and how — including what is only stated by Plorea rather than seen, and
what is known to be
[impossible to reproduce in test](docs/api-behaviour.md#what-cannot-be-reproduced-in-test).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). `composer check` (Pint, Rector,
PHPStan level 8, PHPUnit) must pass.

## Security

Report vulnerabilities via [SECURITY.md](SECURITY.md), not a public issue.
Never commit real credentials — not in tests, fixtures, or comments.

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
