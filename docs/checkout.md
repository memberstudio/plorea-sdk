# Embedded and native checkout

Plorea's hosted pay page (`pay.plorea.no`) is Adyen's Drop-in mounted on a
session. You can open the same session yourself and mount Drop-in in your own
web page or in a native iOS or Android app.

| Surface | What you need | Status |
| --- | --- | --- |
| Hosted pay page (`$link->url`), in a browser or a WebView | Nothing | Works |
| Drop-in on your own domain | A client key and your origins whitelisted by Plorea | Supported by Plorea (2026-09-15). Not yet mounted end to end through this SDK |
| Native payment (`payments/session`) | `Channel::IOS` / `Channel::Android`, plus the configured client key | **Verified on iOS** (2026-09-17): authorises end to end. `channel` still ignored, no `clientKey` in the response |
| Native card setup (`payment-methods/setup/session`) | As above | **Live in test** (2026-09-17): `channel` validated and echoed, `clientKey` returned |

**What probing Plorea's test environment showed on 2026-09-17**, after
Plorea's native rollout the same day:

- **Card setup** validates `channel` (`Web`, `iOS`, `Android`, case-sensitive;
  anything else is a `400`), echoes it as `$session->channel`, and returns a
  `clientKey` for **every** channel, `Web` included.
- **Payments** still accept any `channel` and return `clientKey: null`, so a
  payment session uses `PLOREA_ADYEN_CLIENT_KEY`. That fallback is what makes
  native payment work today: an iOS Drop-in mounted on such a session
  authorised a test card the same evening.
- The key card setup returns may **differ** from the key Plorea sent you out
  of band. Both can be valid: Adyen checks allowed origins **per key**, and
  only when the caller sends an `Origin` header. Replaying Adyen's
  `/sessions/{id}/setup` preflight, both keys were accepted with no `Origin`
  (what a native app sends), while only the returned key was accepted from a
  whitelisted web origin. Prefer the key in the response — which is what the
  DTO already does — and check with Plorea which key carries your origins.

Keep passing the channel on both endpoints. See
[Verified API behaviour](api-behaviour.md).

## What to ask Plorea for

Before you build anything, ask Plorea for:

- **An Adyen client key**, one for test and one for live. Each deployment
  holds only the one matching its `PLOREA_ENVIRONMENT`. It is publishable,
  since it ends up in the browser or the app. It is still issued to you, so
  keep it in your environment and never commit it.
- **Your web origins whitelisted**: every domain that mounts Drop-in. A
  wildcard entry (`https://*.example.com`) covers subdomains at any depth, but
  **not** the bare domain — whitelist that separately. `localhost` is not
  whitelisted (2026-09-17), so develop against a whitelisted staging domain.
  An origin that is not on the list fails
  late, in the Drop-in's `/sessions/{id}/setup` preflight, as a CORS error that
  looks nothing like a credentials problem.
- **Your app identifiers whitelisted**, for the native path only: the iOS
  bundle id with your Apple Team ID, and the Android package name. Staging and
  development builds usually have different identifiers, so list those too.

If your customers serve checkout from **their own domains**, each of those
domains is an origin that Plorea has to whitelist by hand. Plorea recommends
serving the payment step from a wildcard subdomain you control instead, such as
`{customer}.example.com`, so one wildcard entry covers every customer.

```dotenv
PLOREA_ENVIRONMENT=test
PLOREA_ADYEN_CLIENT_KEY=test_...   # production: PLOREA_ENVIRONMENT=live with the live_... key
```

## The server side

The app or browser never talks to Plorea. Your API key stays on your server, so
your backend opens the session and passes on only what Drop-in needs:

```php
use MemberFlow\Plorea\Enums\Channel;
use MemberFlow\Plorea\Facades\Plorea;
use MemberFlow\Plorea\Exceptions\PaymentAlreadyPaidException;

public function store(Request $request, Invoice $invoice)
{
    $this->authorize('pay', $invoice);

    $channel = Channel::from($request->validate([
        'channel' => ['required', Rule::enum(Channel::class)],
    ])['channel']);

    $link = Cache::lock("plorea:{$invoice->reference}", 10)->block(5, fn () => Plorea::payments()
        ->link($invoice->reference, $invoice->title, $invoice->amount(), route('invoices.paid', $invoice))
        ->merchant(orgNr: $invoice->issuer->org_nr)
        ->firstOrCreate());

    $session = Plorea::payByLink()->session(
        $link->id,
        returnUrl: $this->returnUrlFor($channel, $invoice),
        channel: $channel,
    );

    return response()->json($session); // sessionId, sessionData, clientKey, environment — nothing else
}
```

- **Serialize the session, not its `raw`.** `PaymentSession` and
  `PaymentMethodSession` serialize to `toCheckout()`, which holds
  `sessionId`, `sessionData`, `clientKey` and `environment`. The rest of the
  response, including tenant, shopper reference and customer id, stays on your
  server.
- **The client key is resolved for you.** A `clientKey` in the response wins:
  card setup sends one, payments do not (observed 2026-09-17), so a payment
  session falls back to `plorea.adyen_client_key`. `environment` falls back to
  `plorea.environment` the same way. The configured key must match the
  environment — a `test_` key with `PLOREA_ENVIRONMENT=live`, or the reverse,
  throws instead of failing later inside Drop-in.
- **Create a session per attempt.** Sessions expire, so do not cache them.
  Reuse the payment link (`firstOrCreate()`), not the session.
- **Pick the `returnUrl` on the server.** Never take it from the request,
  because that is an open redirect. Choose it by channel:

```php
protected function returnUrlFor(Channel $channel, Invoice $invoice): string
{
    return match ($channel) {
        Channel::Web => route('invoices.paid', $invoice),
        // A universal link / App Link on your domain (recommended), or your app's custom scheme.
        Channel::IOS, Channel::Android => config('app.mobile_return_url'),
    };
}
```

**Both endpoints accept a custom-scheme `returnUrl`** such as
`myapp://payment/return` (observed 2026-09-17; `payments/session` rejected one
earlier that day). Universal links (iOS) and App Links (Android) are still what
Apple, Google and Plorea recommend, and they need the
`apple-app-site-association` and `assetlinks.json` files on your domain.

## The web side

With Adyen Web v6:

```js
const session = await fetch(`/api/invoices/${id}/checkout`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
    body: JSON.stringify({ channel: 'Web' }),
}).then((response) => response.json());

const checkout = await AdyenCheckout({
    clientKey: session.clientKey,
    environment: session.environment,          // "test" or "live"
    session: { id: session.sessionId, sessionData: session.sessionData },
    onPaymentCompleted: () => location.assign(`/invoices/${id}/paid`),
    onPaymentFailed: () => showRetry(),
    onError: (error) => report(error),
});

new Dropin(checkout).mount('#checkout');
```

Some payment methods (3-D Secure, Vipps and other redirect methods) leave the
page. The customer comes back to your `returnUrl` with `sessionId` and
`redirectResult` in the query. Finish the payment there:

```js
const params = new URLSearchParams(location.search);

const checkout = await AdyenCheckout({
    clientKey, environment,
    session: { id: params.get('sessionId') },
    onPaymentCompleted, onPaymentFailed,
});

checkout.submitDetails({ details: { redirectResult: params.get('redirectResult') } });
```

**Content Security Policy.** Drop-in loads from and connects to Adyen's
`checkoutshopper-*.adyen.com` hosts, and 3-D Secure opens an iframe to the
card issuer. A strict CSP needs `script-src`, `style-src`, `connect-src` and
`frame-src` entries for these. See Adyen's CSP guide for the current list.

## The native side

Ask your backend for a session with `channel: "iOS"` or `"Android"` and pass
the four fields to Adyen's native SDK in session mode:

- **iOS** (`Adyen` / `AdyenSession`): build an `APIContext` from `environment`
  and `clientKey`, then call `AdyenSession.initialize(with:)` using
  `sessionIdentifier` and `initialSessionData`. Handle the return URL in your
  scene delegate and pass it to `RedirectComponent.applicationDidOpen(from:)`.
- **Android** (`com.adyen.checkout:drop-in`): build a `CheckoutSession` from
  `sessionId` and `sessionData` and start Drop-in with it. Declare the return
  URL's intent filter on the activity that handles it.

Map `environment` to the SDK's own value: `test` is the test environment, and
`live` is Adyen's live **Europe** environment.

**Handle the redirect, not just the native challenge.** Plorea's sessions do
not request native 3DS2, so a 3-D Secure challenge opens in a browser and comes
back through your `returnUrl` (observed on iOS, 2026-09-17). The return-URL
path is on the critical path for card payments, not an edge case.

## The result: never trust the client

`onPaymentCompleted` and the native result callbacks are hints for the UI.
They are not a record of payment. Treat the outcome like any other payment:

1. The client tells your backend "done". The backend re-reads
   `Plorea::payments()->status($reference)` and answers with the real state.
2. The `payment.authorised` / `payment.failed` webhook is the authoritative
   push. See [Webhooks](webhooks.md). Use it to update the app over whatever
   channel you have (broadcasting, push notification), so a customer who
   closed the app still sees the result.
3. Webhook, status poll and return page can race. Book the payment
   idempotently on the reference.

## Card setup

The same applies to stored cards, with two differences:

```php
$session = Plorea::paymentMethods()
    ->setup($customer->shopper_reference, RecurringType::Subscription, $returnUrl)
    ->channel(Channel::Android)
    ->session();

$customer->update(['pending_payment_method_id' => $session->paymentMethodId]);

return response()->json($session);
```

- **There is no webhook for card setup.** Poll
  `Plorea::paymentMethods()->find($id)` from the backend until it is `active`
  or `failed`. See [Payment methods](payment-methods.md#polling-for-the-result).
- **Card setup returns its own client key**, for every channel, and
  `$session->channel` says which channel Plorea opened the session for
  (observed 2026-09-17). The response's key wins over the configured one.

## Apple Pay and Google Pay

Drop-in shows wallets only when the merchant account is set up for them. That
setup lives in Plorea's Adyen account, not in your app:

- **Apple Pay on the web** requires Apple's domain-association file on every
  domain that shows the button.
- **Apple Pay in an iOS app** requires an Apple merchant id and a payment
  processing certificate.
- **Google Pay** requires a Google Pay merchant id for production.

Ask Plorea which wallets they offer, and who owns the merchant id and
certificate, before promising them.

## App store rules

Apple and Google require their own in-app purchase systems for **digital**
goods and services used inside the app. Payments for physical goods and
real-world services, such as a gym membership, a class or an invoice, are
generally exempt and may use Adyen. Check your case against the current App
Store Review Guidelines (section 3.1) and Google Play's payments policy before
you submit.

## Testing

`Plorea::fake()` answers both session endpoints the way Plorea does today: a
payment session has no client key, so your configured key is resolved; a card
setup session returns `test_FAKE_CLIENT_KEY` and echoes the channel. Assert on what you sent:

```php
Plorea::fake();

$this->postJson("/api/invoices/{$invoice->id}/checkout", ['channel' => 'iOS'])
    ->assertOk()
    ->assertJsonStructure(['sessionId', 'sessionData', 'clientKey', 'environment'])
    ->assertJsonMissingPath('raw');

Plorea::assertSent(fn (RecordedRequest $request) => $request->path === 'payments/session'
    && $request->input('channel') === 'iOS');
```
