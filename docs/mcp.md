# MCP server

The SDK ships a local, read-only [MCP](https://modelcontextprotocol.io) server
for coding agents that build on Plorea. It checks your configuration, returns
the field-tested notes in this documentation and reads objects from Plorea. No
tool on it creates, charges, refunds or cancels anything, and no tool prints a
key or a secret.

## Turn it on

The server needs `laravel/mcp`, which the SDK only suggests.

1. Install the package: `composer require laravel/mcp`.
2. Set `PLOREA_MCP_ENABLED=true` in the environment where the agent runs.
3. Register the server with your agent. Use the command
   `php artisan mcp:start plorea`.

Example for Claude Code, in `.mcp.json`:

```json
{
    "mcpServers": {
        "plorea": {
            "command": "php",
            "args": ["artisan", "mcp:start", "plorea"]
        }
    }
}
```

Laravel Boost runs its own MCP server, and a package cannot add tools to it.
The Plorea server is a second server next to it.

## Tools

### `check-setup`

Checks the configuration against the [going-live](going-live.md) list. Each
item reports `pass`, `warn`, `fail` or `n/a`, with a fix:

| Check | Fails or warns when |
| --- | --- |
| `api_key` | No key, or the key does not start with `plr_test_` / `plr_live_` |
| `environment` | `PLOREA_ENVIRONMENT` is not `test` / `live`, or it does not match the key prefix (Plorea answers `403` on every call) |
| `tenant_id` | No tenant id |
| `platform` | No platform identifier |
| `webhook_verify` | Verification is off (a warning in test, a failure in live) |
| `webhook_secret` | No signing secret (the route rejects every delivery) |
| `webhook_route` | The `plorea.webhook` route is not registered |
| `webhook_csrf` | The route (after `withoutMiddleware`) or the global stack runs Laravel's CSRF middleware or a subclass of it (every delivery gets `419` unless the path is excluded) |
| `retries` | `PLOREA_RETRY_TIMES` is above 0 (non-idempotent writes can bill twice) |
| `adyen_client_key` | The key's `test_` / `live_` prefix does not match the environment |

Pass `ping: true` to make one read-only call: a status lookup for the
reference `plorea-mcp-ping`, which does not exist. Plorea's `404` "Payment
not found" proves that Plorea accepts the key and the environment. Any other
`404` (a proxy, or a base URL with a path) is a warning. A `401` or `403` fails
the check.

The output contains no configured value, and no webhook path. The key prefix
and the environment name are the only parts of the configuration that appear.
Both come from a fixed list, so a secret pasted into the wrong variable is not
echoed. The environment check compares the exact value, the same way the client
does: `" test "` and `"Test"` fail.

### `field-note`

Takes an HTTP status (`403`), an exception class (`ChargeFailedException`,
short or fully qualified), a status string (`payment_failed`) or a keyword
(`refund`, `webhook`, `retry`). Returns up to five matching notes, each with
its evidence and the heading in `docs/` it comes from. An unmatched query
returns the list of topics.

The notes ship in `resources/field-notes.json`, because `docs/` is not part of
the package that lands in `vendor/`. A test fails when a note points at a
heading that no longer exists. When you change a finding in `docs/`, update
its note in the same PR.

### `lookup`

Reads one object from Plorea with a single `GET` request. Pass a `kind` and an
`id`:

| `kind` | `id` | SDK call |
| --- | --- | --- |
| `payment` | The payment reference | `payments()->status()` |
| `payment_link` | The payment link id | `payByLink()->find()` |
| `subscription` | The subscription id | `subscriptions()->find()` |
| `subscription_charges` | The subscription id | `subscriptions()->charges()`, newest first, at most 50 |
| `subscriptions_for_external_id` | Your external id | `subscriptions()->forExternalId()`, at most 50 |
| `payment_method` | The payment method id | `paymentMethods()->find()` |

A `404` is an answer (`found: false`), not an error: the id is unknown, or it
belongs to the other environment.

**Live is blocked.** `lookup` refuses when `PLOREA_ENVIRONMENT` is `live` or
the key starts with `plr_live_`, and sends nothing. Since 2026-10-13 the key
decides the environment, so a live key with the environment unset still counts
as live. Set `PLOREA_MCP_ALLOW_LIVE=true` to allow live reads.

**Personal data is masked.** Every DTO has an allowlist in
`src/Mcp/Masker.php`. A field on it is kept or masked. A masked field shows
`****` when it has a value, and `null` when it has none. A field that is not on
the list never appears, and neither does `raw`: a field that Plorea adds later
stays hidden until someone classifies it. A test fails when a DTO field is not
on its list.

| Kept | Masked |
| --- | --- |
| Status, amounts, currency, dates, VAT, payment references, PSP references, subscription, charge and payment method ids, event codes, `failureReason`, card brand and funding source | `customerId`, `doneId`, `shopperReference`, `storedPaymentMethodId`, card digits and expiry, `externalId`, `orderId`, `title`, `product`, `platform`, `store`, refund and cancel references, `description`, `metadata`, refund and cancel reasons, merchant name and org number, return, invoice and pay URLs |

Text and ids that your app chooses are masked, because an app can put an
e-mail or a name in them. The payment reference is kept: an agent needs it to
follow a payment, so do not put personal data in it.

Set `PLOREA_MCP_MASK=false` to see masked fields. Do that only on your own
machine with test data.

A setting that is not clearly true or false, such as an empty or blank value,
keeps the safe value: live stays blocked and masking stays on.

`lookup` also asks the Plorea client which key it holds. A client that was
built with a live key stays blocked, even when the app config later says
test.

## Configuration

| Env | Config | Default | Purpose |
| --- | --- | --- | --- |
| `PLOREA_MCP_ENABLED` | `plorea.mcp.enabled` | `false` | Register the `plorea` MCP server |
| `PLOREA_MCP_ALLOW_LIVE` | `plorea.mcp.allow_live` | `false` | Let `lookup` read live data |
| `PLOREA_MCP_MASK` | `plorea.mcp.mask` | `true` | Mask personal data in `lookup` output |

See also: [Going live](going-live.md) · [Error handling](errors.md) · [Verified API behaviour](api-behaviour.md)
