---
name: plorea-checkout
description: "Use this skill when building embedded or native checkout with the Plorea SDK (memberflow/plorea): mounting Adyen Drop-in on your own domain, paying or storing cards from an iOS or Android app with Adyen's native SDK, building the backend endpoint that opens a Plorea session for a browser or app, handling Adyen return URLs, redirectResult, universal links / App Links, the Adyen client key (PLOREA_ADYEN_CLIENT_KEY), CSP for Adyen, Apple Pay / Google Pay, or App Store payment rules. Covers Channel, payByLink()->session(), paymentMethods()->setup()->channel()->session(), toCheckout(), and verifying results server-side."
license: MIT
metadata:
  author: memberflow
---
# Plorea embedded and native checkout

`pay.plorea.no` is Adyen Drop-in on a session. Your backend can open the same session and hand it to Drop-in in your own page or in a native app. **A WebView on the hosted pay page needs none of this** — prefer it unless you need an embedded experience.

## Prerequisites from Plorea (ask first)

- An Adyen client key (test and live) → `PLOREA_ADYEN_CLIENT_KEY`, holding only the one matching the deployment's `PLOREA_ENVIRONMENT`. Publishable, but keep it in env, never in code.
- Web: every origin that mounts Drop-in whitelisted (plus `http://localhost:*`). A missing origin fails late as a CORS error in `/sessions/{id}/setup`.
- Native: iOS bundle id + Apple Team ID and Android package name whitelisted — including staging builds.
- Customer-owned domains are origins too; agree how they get added, or serve payment from a domain you control.

## Backend endpoint

The app/browser never calls Plorea — the API key stays on the server.

```php
use MemberFlow\Plorea\Enums\Channel;
use MemberFlow\Plorea\Facades\Plorea;

$channel = Channel::from($request->validate(['channel' => ['required', Rule::enum(Channel::class)]])['channel']);

$link = Cache::lock("plorea:{$invoice->reference}", 10)->block(5, fn () => Plorea::payments()
    ->link($invoice->reference, $invoice->title, $amount, route('invoices.paid', $invoice))
    ->merchant(orgNr: $invoice->issuer_org_nr)
    ->firstOrCreate());

$session = Plorea::payByLink()->session($link->id, returnUrl: $serverChosenReturnUrl, channel: $channel);

return response()->json($session); // {sessionId, sessionData, clientKey, environment} only
```

Card storage:

```php
$session = Plorea::paymentMethods()
    ->setup($shopperReference, RecurringType::Subscription, $serverChosenReturnUrl)
    ->channel($channel)
    ->session();
// store $session->paymentMethodId, then return response()->json($session)
```

Rules:
- **Never return `$session->raw`** or build the JSON by hand from it — it carries tenant, shopper reference and customer id. The DTO serializes to `toCheckout()`.
- `clientKey`: the response's if it has one (none does today, native included), else `PLOREA_ADYEN_CLIENT_KEY`, whose `test_` / `live_` prefix must match `PLOREA_ENVIRONMENT` or the session throws. `environment`: the response's, else `PLOREA_ENVIRONMENT` (`test` / `live` = Adyen live Europe).
- **Choose `returnUrl` on the server** by channel (web route; universal link / App Link for apps — `payments/session` rejects custom schemes with a 400). Taking it from the request is an open redirect.
- New session per attempt; reuse the link, not the session.
- Authorize the user against the invoice / customer before opening a session.

## Web client (Adyen Web v6)

```js
const checkout = await AdyenCheckout({
    clientKey: s.clientKey, environment: s.environment,
    session: { id: s.sessionId, sessionData: s.sessionData },
    onPaymentCompleted, onPaymentFailed, onError,
});
new Dropin(checkout).mount('#checkout');
```

On the return page (3DS, redirect methods): re-create `AdyenCheckout` with `session: { id: sessionId }` from the query and call `checkout.submitDetails({ details: { redirectResult } })`. CSP must allow Adyen's `checkoutshopper-*` hosts (script/style/connect/frame) and issuer 3DS frames; live hosts differ from test.

## Native clients

- iOS: `AdyenSession` with an `APIContext(environment:, clientKey:)`, `sessionIdentifier`, `initialSessionData`; forward the return URL to `RedirectComponent.applicationDidOpen(from:)`.
- Android: `CheckoutSession` from `sessionId` / `sessionData`, start Drop-in; declare the return URL intent filter.
- Universal links need `apple-app-site-association`; App Links need `assetlinks.json`.

## Results: never trust the client

Client callbacks are UI hints. On "done", the backend re-reads `Plorea::payments()->status($reference)` (or `paymentMethods()->find($id)` for card setup) and answers with the real state. Payment webhooks are the authoritative push — use them to notify the app. **Card setup emits no webhook**: poll from the backend until `active` or `failed`. Book idempotently on the reference; webhook, poll and return page race.

## Unverified — do not promise these

Stated by Plorea but not live in test (probed 2026-09-17): both session endpoints accept any `channel` and ignore it, and no response carries a `clientKey`. A native app uses the configured key, with its bundle id / package name whitelisted for that key. Unobserved: a Drop-in mounted end to end from a whitelisted origin. Wallets (Apple Pay / Google Pay) depend on Plorea's Adyen account setup and Apple domain verification — ask Plorea.

## App stores

Apple / Google require in-app purchase for digital goods consumed in the app. Payments for physical goods and real-world services (memberships, classes, invoices) are generally exempt — check App Store Review Guidelines 3.1 before submitting.

## Testing

`Plorea::fake()` answers both session endpoints and returns no client key for any channel, as Plorea does today. Assert the JSON has exactly `sessionId`, `sessionData`, `clientKey`, `environment`, and `Plorea::assertSent(fn ($r) => $r->input('channel') === 'iOS')`.
