# Payments

One-off payments in Plorea are **payment links**: you create a link, send your
customer to `pay.plorea.no`, and read the outcome back by reference.

## Create a payment link

`link()` takes the four required fields; everything else is chained.

```php
use MemberFlow\Plorea\Data\Amount;
use MemberFlow\Plorea\Facades\Plorea;

$link = Plorea::payments()
    ->link(
        reference: 'FIN-2026-00123',          // your unique reference
        product: 'Faktura FIN-2026-00123',    // shown to the customer
        amount: Amount::nok(450000),          // 4 500,00 kr
        returnUrl: 'https://app.example/paid',
    )
    ->payerEmail('kunde@eksempel.no')
    ->invoiceUrl('https://app.example/invoices/123.pdf')
    ->orderId('ORD-99')
    ->merchant(orgNr: '912650774', name: 'Techify AS', email: 'post@techify.no')
    ->create();

$link->url;        // https://pay.plorea.no/... — send the customer here
$link->id;         // pl_...
$link->reference;  // echoed back
$link->expiresAt;  // CarbonImmutable|null — store this
```

`reference` is yours and is the key you use for everything afterwards: status,
refunds, cancellations, webhook correlation. Use your own invoice or order
number.

**Store `expiresAt`.** It is the only reliable expiry signal — see
[Status](#status) below.

### The merchant is required

```php
->merchant(orgNr: '912650774', name: 'Techify AS', email: 'post@techify.no')
```

`merchantOrgNr` tells Plorea **who receives the payout**, so it is always the
invoice issuer's organisation number — your client's, never your own platform's.

The Plorea API accepts a link without it and only fails later, so the SDK
refuses to create one: `create()` throws `PloreaException` when the org number
is missing. That is a deliberate divergence from the API — a link that cannot
be paid out is worse than a loud failure at creation time.

`merchantName` and `merchantEmail` are optional but recommended; they are used
for KYC communication. Do **not** send a store or balance account — Plorea
resolves those from the org number, and `->store()` is deprecated for that
reason.

The create response does **not** echo the merchant back. The org number
reappears only on the pay page:

```php
Plorea::payByLink()->find($link->id)->merchantOrgNr;
```

### KYC is implicit

The first payment for a new `merchantOrgNr` starts merchant onboarding
automatically. The merchant receives an onboarding email (at `merchantEmail`
when you supplied one). The link is payable throughout — the payout is simply
held until KYC is approved, typically 1–5 business days.

## Reuse or create (`firstOrCreate`)

Plorea has **no idempotency on duplicate references**. Creating twice gives you
two live links for the same invoice, and both are payable.

```php
$link = Plorea::payments()
    ->link('FIN-2026-00123', 'Faktura FIN-2026-00123', Amount::nok(450000), 'https://app.example/paid')
    ->merchant(orgNr: '912650774')
    ->firstOrCreate();
```

`firstOrCreate()` looks the reference up first and then:

| Existing state | Result |
| --- | --- |
| Open, same amount / currency / tenant / environment, not expired | Returned as-is |
| Open but expired, dead (cancelled, refunded), or a different amount | Superseded — a new link on a suffixed reference (`FIN-2026-00123-1`, `-2`, …) |
| Already paid | Throws `PaymentAlreadyPaidException` (carries the `PaymentStatus`) |
| Unknown (404) | Creates the link |

Expiry is checked against the **pay page**, not the status endpoint: the status
endpoint exposes no expiry and its status string has never been observed to
flip to `expired`, so an open-but-expired link would otherwise be handed out
again.

```php
use MemberFlow\Plorea\Exceptions\PaymentAlreadyPaidException;

try {
    $link = $pending->firstOrCreate();
} catch (PaymentAlreadyPaidException $e) {
    $e->status->pspReference;  // the settled payment
    // Never create a fresh link for a settled invoice — it would be payable twice.
}
```

Two caveats:

- **Not atomic.** Two requests racing on the same new reference can both
  create a link. Serialise per reference (`Cache::lock("plorea:{$reference}")`)
  if double submits are possible.
- **The suffix scheme assumes `{reference}-1` is not itself a real invoice** in
  your numbering. `maxAttempts` (default 10) bounds the search.

## Status

```php
$status = Plorea::payments()->status('FIN-2026-00123');

$status->isPaid();             // authorised or paid — money moved
$status->isOpen();             // created, pending, active — still payable
$status->isRefundRequested();  // refund accepted, provider settling
$status->isCancelRequested();  // cancel accepted, provider settling
$status->is('refunded');       // any raw string
$status->status;               // the raw string
$status->amount;               // Amount|null
$status->pspReference;
```

Use the helpers, not string comparison. Observed statuses:

| Group | Statuses |
| --- | --- |
| Open | `created`, `pending`, `active` |
| Paid | `authorised`, `paid` — test payments settle on `authorised` |
| Modification pending | `refund_requested`, `cancel_requested` |
| Terminal | `cancelled`, `refunded`, `failed` (refused by the acquirer) |

A refusal is the one that surprises people: **a declined payment reports
`failed`, not `refused`** — captured 2026-09-09 from a real payment link taken
through a completed 3DS2 challenge and then declined by the acquirer. The
decline itself is carried by the inbound-webhook fields
(`webhookEventCode: "AUTHORISATION"`, `webhookSuccess: false`), and there is no
`failureReason`-style field anywhere on this shape, so `status` is the only
signal. `isPaid()` and `isOpen()` both correctly return false for it.

`expired` is handled defensively but **has never been observed live**. Links
past their expiry keep reporting an open status, so judge expiry from the
`expiresAt` you stored at creation (or from the pay page's computed `expired`
flag), never from the status string.

An unknown reference throws `NotFoundException` (404). An open, never-paid link
returns 200.

> [!NOTE]
> `webhookEventCode`, `webhookSuccess` and `lastWebhookAt` describe the inbound
> Adyen webhook **Plorea** received for the payment. They say nothing about the
> webhook Plorea sends to your application.

Before booking money, compare `$status->amount` against what you expected
locally and flag mismatches for manual handling rather than auto-booking.

## Refund and cancel

```php
Plorea::payments()->refund(
    reference: 'FIN-2026-00123',
    modificationReference: 'FIN-2026-00123-refund-1',  // your unique reference for this attempt
    amount: Amount::nok(450000),                        // omit for a full refund
    reason: 'Customer requested refund',
);

Plorea::payments()->cancel('FIN-2026-00123', 'FIN-2026-00123-cancel-1');
```

Cancel a payment that has not settled; refund one that has.

Both return **immediately** with `refund_requested` / `cancel_requested` and
the provider settles asynchronously. Poll `status()` or wait for a
`payment.refunded` webhook for the final state. The requested status persists
in the meantime, so do not treat it as a failure.

### Keep the refund response — the PSP reference is only there

`refund()` returns a `Refund` whose `$refundPspReference` identifies the refund
at the provider. **That value never appears on `payments()->status()`.** The
status shape carries `lastRefundRequestPspReference`, which is a different
identifier for a different thing: the *request*, not the resulting refund.

So if you throw the `Refund` away and expect to recover the PSP reference from
a later status poll, you cannot — and it is the identifier Plorea and Adyen
support will ask you for. Persist it at the moment of the refund.

There is also **no per-refund status field anywhere**. Until settlement, the
only signal is the payment's own top-level `status` being `refund_requested`,
plus the `lastRefund*` group. The top-level status flips there immediately
(from `paid` / `authorised`) while `webhookEventCode` stays `AUTHORISATION` —
that field describes the original authorisation and does not track the refund.

> [!IMPORTANT]
> **Settlement took over 11 hours** in one observed test-environment refund
> (2026-09-09). Size any polling loop in hours, not minutes: a job that gives
> up after an hour will report a perfectly healthy refund as failed. Never
> reverse a refund in your own books, or tell a customer it failed, because a
> timeout elapsed — `refund_requested` means Adyen accepted it.

`modificationReference` is your idempotency key for the modification itself.

## The pay page

```php
$page = Plorea::payByLink()->find($link->id);

$page->expired;         // computed by Plorea — the authoritative expiry answer
$page->amount;
$page->merchantOrgNr;   // only place the merchant is echoed back
$page->merchantName;
$page->raw['partnerSplits'] ?? null;  // populated once a merchant is attached
```

If you render your own checkout instead of redirecting to `pay.plorea.no`,
open an Adyen Drop-in session for the link:

```php
$session = Plorea::payByLink()->session($link->id, returnUrl: 'https://app.example/paid');

$session->sessionId;
$session->sessionData;
$session->clientKey;
```

## A complete flow

```php
// 1. Create (or reuse) the link and store its id, reference and expiry.
$link = Plorea::payments()
    ->link($invoice->reference, $invoice->title, Amount::nok($invoice->ore), route('paid'))
    ->payerEmail($invoice->customer->email)
    ->merchant(orgNr: $invoice->issuer->org_nr, name: $invoice->issuer->name)
    ->firstOrCreate();

$invoice->update([
    'plorea_link_id' => $link->id,
    'plorea_expires_at' => $link->expiresAt,
]);

// 2. Send the customer to $link->url.

// 3. React to the webhook — as a ping, not as truth.
Event::listen(PaymentStatusUpdated::class, function ($event) {
    $status = Plorea::payments()->status($event->reference);

    if ($status->isPaid()) {
        Invoice::where('reference', $event->reference)->first()?->markPaid($status);  // idempotently
    }
});

// 4. Poll open links on a schedule as a backstop — webhook delivery is best-effort.
```

The webhook, a status poll and the customer's return page can all race on the
same reference. Make the booking idempotent; do not rely on ordering.

See also: [Webhooks](webhooks.md) · [Error handling](errors.md) · [Verified API behaviour](api-behaviour.md)
