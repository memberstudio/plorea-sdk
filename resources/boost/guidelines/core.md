# Plorea SDK (memberflow/plorea)

Laravel SDK for the Plorea Payments API. Entry point is the `MemberFlow\Plorea\Facades\Plorea` facade: `Plorea::payments()`, `Plorea::paymentMethods()`, `Plorea::subscriptions()`. Resources return fluent pending builders; terminal methods (`create()`, `save()`, ...) return final readonly DTOs.

## Non-negotiable conventions

- **Amounts are minor units (øre/cents).** Use `MemberFlow\Plorea\Data\Amount` — `Amount::nok(450000)` is 4 500,00 kr. Never pass major units.
- **Statuses:** open = `created` / `pending` / `active`; paid = `authorised` / `paid` (test payments settle on `authorised`). Use the helpers `isPaid()`, `isOpen()`, `isRefundRequested()`, `isCancelRequested()` instead of comparing strings. `refund_requested` / `cancel_requested` mean the provider is settling asynchronously — poll `status()` for the final state. `expired` is never reported live; judge expiry from the `expiresAt` stored at creation, not the status string.
- **No idempotency on duplicate references.** `->firstOrCreate()` (instead of `->create()`) reuses an open link, supersedes dead ones with a suffixed reference, and throws `PaymentAlreadyPaidException` for an already-paid reference — handle that exception, never swallow it.
- **`merchantOrgNr` is required on payment links** — always the invoice issuer's (the client's) org number, never the platform's own; it tells Plorea who receives the payment. The SDK throws `PloreaException` from `create()` without it. `->merchant(orgNr: ..., name: ..., email: ...)` — name and email are optional but recommended for KYC communication. Never send a store or balance account; Plorea resolves those from the org number. The first payment for a new org number starts KYC automatically (the merchant gets an onboarding email); the link is payable while KYC is pending, with the payout held in escrow until approval (1–5 business days).
- **Webhooks are pings, not truth.** Listen for `MemberFlow\Plorea\Events\PaymentStatusUpdated` and re-fetch `Plorea::payments()->status($event->reference)` — never book money based on payload fields. Handle idempotently: webhook, status poll, and the customer's return page can race on the same reference. Exclude the webhook path (`plorea/webhook` by default) from CSRF verification.
- **Never log or serialize the API key**; redact `Authorization` headers in any debug output. The webhook route fails closed until `PLOREA_WEBHOOK_SECRET` is set (`PLOREA_WEBHOOK_VERIFY=false` is acceptable on staging only).

## Testing

Always use `Plorea::fake()` in tests — no HTTP leaves the suite, every endpoint has a sensible default response. Assert with `Plorea::assertSent('payments/link')` / closures / `assertSentCount()`. Stub endpoints with arrays, callables, or exceptions: `Plorea::fake(['payments/status/*' => ['reference' => 'ref-1', 'status' => 'refused']])`. An unknown reference throws `NotFoundException`, mirroring the live 404.

## Errors

All exceptions extend `MemberFlow\Plorea\Exceptions\PloreaException`: `ValidationException` (400), `AuthenticationException` (401/403), `ChargeFailedException` (402, declined subscription charge), `NotFoundException` (404), `ServerException` (5xx), `ConnectionException`, `PaymentAlreadyPaidException`. `RequestException` subclasses expose `$e->status` and `$e->response?->json()`.

For full flows (payment links, stored cards, subscriptions, webhooks) use the `plorea-payments` skill.
