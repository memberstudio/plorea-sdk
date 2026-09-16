# Payment methods

A payment method is a **stored card** — the token a subscription charges. You
create one before you create a subscription; the two are separate objects with
separate lifecycles.

## Recurring types

```php
use MemberFlow\Plorea\Enums\RecurringType;

RecurringType::Subscription;            // scheduled recurring billing
RecurringType::CardOnFile;              // customer-initiated later payments
RecurringType::UnscheduledCardOnFile;   // merchant-initiated, irregular (e.g. usage top-ups)
```

The type is Adyen's contract for the mandate and is echoed back verbatim on
setup. Use `Subscription` for anything Plorea's scheduler will bill.

## Setting up a card

Two flows, same builder.

### Hosted redirect

```php
$method = Plorea::paymentMethods()
    ->setup('customer-123', RecurringType::Subscription, 'https://app.example/return')
    ->customerId('cus_local_1')
    ->description('Done CRM Pro')
    ->create();

return redirect($method->adyenPaymentLinkUrl);
```

### Adyen Drop-in

```php
$session = Plorea::paymentMethods()
    ->setup('customer-123', RecurringType::Subscription, 'https://app.example/return')
    ->session();

$session->paymentMethodId;  // pm_... — store this now

return response()->json($session);  // sessionId, sessionData, clientKey, environment
```

Add `->channel(Channel::IOS)` or `->channel(Channel::Android)` for Adyen's
native SDKs. The web Drop-in needs `PLOREA_CLIENT_KEY` and your origins
whitelisted by Plorea. See [Embedded and native checkout](checkout.md).

`shopperReference` (the first argument) is Adyen's identifier for the
cardholder and is what ties stored cards to a person. Keep it stable per
customer for the lifetime of the account.

Both flows authorise and immediately reverse a **1 NOK verification charge** to
store the card. The customer is never billed for it, but it does appear
briefly on some statements.

## Polling for the result

There is **no webhook for card setup** — not for success, not for failure.
Poll after the customer returns:

```php
$method = Plorea::paymentMethods()->find($method->id);

$method->isPendingSetup();  // status pending_setup — the customer has not finished
$method->isActive();        // status active — card stored and chargeable
$method->hasFailed();       // status failed — terminal

$method->storedPaymentMethodId;  // Adyen's token — null until active
$method->cardLast4;              // "1111"
$method->cardBrand;              // "visa"
$method->expiryDate;             // "03/2030"
$method->consentAt;
```

| Status | Meaning | Next step |
| --- | --- | --- |
| `pending_setup` | Customer has not completed the flow | Keep polling; `expiresAt` bounds the window |
| `active` | Card stored | Create the subscription |
| `failed` | Verification refused, **no card stored** | Start a new setup — terminal |

`failed` is terminal in the strong sense: the verification authorisation was
refused, so no token exists and no retry of *this* method can ever succeed.
`storedPaymentMethodId` stays null. Create a fresh setup instead.

> [!WARNING]
> Do not branch on `failureReason`. It is **null even for a genuine Adyen
> refusal** (verified 2026-09-09). The status is the only signal you have.

## Using it

A subscription requires an `active` method. Both create and update reject
anything else with a 400 `ValidationException`, message "Payment method is not
active" — the create response also names the offending `paymentMethodId`.

```php
use MemberFlow\Plorea\Exceptions\ValidationException;

try {
    Plorea::subscriptions()->create($method->id, Amount::nok(19900), BillingInterval::monthly())->save();
} catch (ValidationException $e) {
    // 400 — the card is not stored. Send the customer through setup again.
}
```

> [!NOTE]
> Plorea's validation messages are **static templates**. A request missing two
> required fields answers with a list of all five. Show them to a developer,
> never parse them, and never render them to a customer as a to-do list.

## Replacing a card

Set up a new method and point the subscription at it — there is no "update the
card on this method" operation:

```php
$new = Plorea::paymentMethods()->setup($shopperReference, RecurringType::Subscription, $returnUrl)->create();
// ... customer completes the flow, poll until isActive() ...

Plorea::subscriptions()->update($subscriptionId)->paymentMethod($new->id)->save();
```

## Testing

Use the public [Adyen test cards](https://docs.adyen.com/development-resources/testing/test-card-numbers/).
`4111 1111 1111 1111` (exp `03/2030`, CVC `737`) stores successfully.

Refusals at setup time can be triggered through Adyen's documented magic
values — a `holderName` such as `NOT_ENOUGH_BALANCE`, or
`additionalData.RequestedTestAcquirerResponseCode` — but both ride on the
`/payments` request, which means they refuse the **verification** and leave you
with a `failed` method rather than a stored card that declines later. See
[Verified API behaviour](api-behaviour.md#what-cannot-be-reproduced-in-test)
for what that blocks.

See also: [Subscriptions](subscriptions.md) · [Verified API behaviour](api-behaviour.md)
