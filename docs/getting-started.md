# Getting started

## Requirements

- PHP 8.4 or 8.5
- Laravel 12 or 13

The package is tested against every combination of those on CI.

## Installation

```bash
composer require memberflow/plorea
```

The service provider is auto-discovered. Publish the config file only if you
need to change something the environment variables do not cover (extra
webhook middleware, HTTP timeouts):

```bash
php artisan vendor:publish --tag=plorea-config
```

## Credentials

```dotenv
PLOREA_API_KEY=plr_test_...
PLOREA_ENVIRONMENT=test        # test | live
PLOREA_TENANT_ID=your-tenant
PLOREA_WEBHOOK_SECRET=         # obtain from Plorea — the webhook route rejects everything until this is set
```

Test and live are separate keys and separate tenants. Nothing in the SDK
switches environment for you — `PLOREA_ENVIRONMENT` is sent as the
`X-Environment` header, and Plorea routes on it.

> [!IMPORTANT]
> Never log, serialise or commit the API key. The SDK strips transfer stats
> from failed responses so exception output cannot leak the `Authorization`
> header, and the `RequestSent` / `ResponseReceived` events deliberately carry
> no headers at all. Keep that property in your own listeners.

## Configuration reference

| Env var | Config key | Default | Purpose |
| --- | --- | --- | --- |
| `PLOREA_API_KEY` | `plorea.api_key` | — | Bearer token for the API |
| `PLOREA_ENVIRONMENT` | `plorea.environment` | `test` | Sent as `X-Environment` on every request |
| `PLOREA_BASE_URL` | `plorea.base_url` | `https://payments.plorea.no` | API base URL |
| `PLOREA_TENANT_ID` | `plorea.tenant_id` | — | Injected into every request that needs one |
| `PLOREA_PLATFORM` | `plorea.platform` | — | Added to every request body — Plorea's internal reporting only. Unset, the field is omitted |
| `PLOREA_TIMEOUT` | `plorea.http.timeout` | `30` | Request timeout, seconds |
| `PLOREA_CONNECT_TIMEOUT` | `plorea.http.connect_timeout` | `10` | Connect timeout, seconds |
| `PLOREA_RETRY_TIMES` | `plorea.http.retry.times` | `0` | Retries for connection errors and 5xx |
| `PLOREA_RETRY_SLEEP` | `plorea.http.retry.sleep` | `100` | Milliseconds between retries |
| `PLOREA_WEBHOOKS_ENABLED` | `plorea.webhooks.enabled` | `true` | Register the webhook route |
| `PLOREA_WEBHOOK_PATH` | `plorea.webhooks.path` | `plorea/webhook` | Route path |
| `PLOREA_WEBHOOK_SECRET` | `plorea.webhooks.secret` | — | Signing secret from Plorea |
| `PLOREA_WEBHOOK_VERIFY` | `plorea.webhooks.verify` | `true` | Staging escape hatch — see [Webhooks](webhooks.md) |
| — | `plorea.webhooks.middleware` | `[]` | Extra middleware for the webhook route |

### Retries

Retries default to **off**. Turn them on only for idempotent traffic. Plorea
has no idempotency on payment-link creation, so a retried `POST payments/link`
that actually succeeded server-side leaves you with two live links —
`firstOrCreate()` exists precisely because of this. Retrying reads
(`status()`, `find()`, `charges()`) is always safe.

## The facade

Everything goes through `MemberFlow\Plorea\Facades\Plorea`:

```php
use MemberFlow\Plorea\Facades\Plorea;

Plorea::payments();        // payment links, status, refunds, cancellations
Plorea::paymentMethods();  // stored cards
Plorea::subscriptions();   // recurring billing
Plorea::payByLink();       // the customer-facing pay page and its Drop-in session
Plorea::client();          // the underlying HTTP client, if you need a raw call
```

Resources return **pending builders** for anything with optional fields. A
builder does nothing until you call its terminal method (`create()`,
`firstOrCreate()`, `save()`, `session()`), which returns a final readonly DTO.
Nothing is mutated in place; every builder method returns a new instance.

```php
$link = Plorea::payments()
    ->link('FIN-2026-00123', 'Faktura', Amount::nok(450000), 'https://app.example/paid')
    ->payerEmail('kunde@eksempel.no')   // builder
    ->merchant(orgNr: '912650774')      // builder
    ->create();                         // terminal — returns PaymentLinkCreated
```

Every DTO also keeps the untouched response in `->raw`, so a field the SDK
does not model yet is never lost:

```php
$link->raw['someNewFieldPloreaAdded'] ?? null;
```

## Multi-tenant apps

`PLOREA_TENANT_ID` is the default, and every builder can override it per
request:

```php
Plorea::payments()->link(...)->tenant('other-tenant')->create();
Plorea::subscriptions()->forExternalId('ws_acme_456', tenantId: 'other-tenant');
```

## Next

- Charging a customer once → [Payments](payments.md)
- Charging a customer repeatedly → [Payment methods](payment-methods.md), then [Subscriptions](subscriptions.md)
- Reacting to state changes → [Webhooks](webhooks.md)
