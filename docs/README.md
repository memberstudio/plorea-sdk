# Plorea SDK documentation

A Laravel SDK for the [Plorea Payments API](https://docs.plorea.no) — payment
links, refunds, stored cards, subscriptions and webhooks.

Start with [Getting started](getting-started.md), then read the guide for
whatever you are building. The [API reference](api-reference.md) lists every
public method, DTO property and event; [Verified API behaviour](api-behaviour.md)
records what has actually been observed against the live Plorea test
environment, and what has not.

## Guides

| Page | Covers |
| --- | --- |
| [Getting started](getting-started.md) | Requirements, installation, configuration reference, the facade |
| [Payments](payments.md) | Payment links, `firstOrCreate`, status, refunds, cancellations, the pay page |
| [Payment methods](payment-methods.md) | Storing cards — hosted redirect and Adyen Drop-in, polling, terminal failures |
| [Embedded and native checkout](checkout.md) | Drop-in on your own domain and in native apps, the client key, return URLs, trusting the result |
| [Going live](going-live.md) | The production checklist: credentials, webhooks, merchants, monitoring |
| [Subscriptions](subscriptions.md) | Create, trials, update, cancel, reactivate, manual charges, charge history |
| [Webhooks](webhooks.md) | Route, signature verification, the event catalogue, listener patterns, what is poll-only |
| [Testing](testing.md) | `Plorea::fake()`, stubs, assertions, the default fixtures |
| [Error handling](errors.md) | The exception hierarchy, HTTP status mapping, retry and idempotency |
| [Request logging](request-logging.md) | `RequestSent` / `ResponseReceived`, building an audit trail |

## Reference

| Page | Covers |
| --- | --- |
| [API reference](api-reference.md) | Every resource method, builder method, DTO property, enum and event |
| [Verified API behaviour](api-behaviour.md) | Field notes: statuses, timing, quirks, and the open questions with Plorea |

## Conventions used throughout

- **Amounts are minor units.** `Amount::nok(450000)` is 4 500,00 kr. There is
  no major-unit constructor, on purpose.
- **Status strings are open.** Every DTO exposes the raw `status` plus typed
  helpers (`isPaid()`, `isActive()`, `is('...')`). Prefer the helpers; an
  unrecognised string passes through rather than throwing, so a new Plorea
  status never breaks your integration.
- **Nothing is persisted.** The SDK owns no tables and no cache. Payment and
  subscription state lives in Plorea; your app stores its own references.
- **Webhooks are pings.** Every documented listener re-fetches authoritative
  state from the API instead of trusting the payload.
