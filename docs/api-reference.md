# API reference

Every public method, DTO property, enum and event. For guidance on *when* to
use them, see the guides linked from the [index](README.md).

All classes live under `MemberFlow\Plorea\`.

---

## Facade — `Facades\Plorea`

| Method | Returns |
| --- | --- |
| `payments()` | `Resources\PaymentResource` |
| `paymentMethods()` | `Resources\PaymentMethodResource` |
| `subscriptions()` | `Resources\SubscriptionResource` |
| `payByLink()` | `Resources\PayByLinkResource` |
| `client()` | `Contracts\Client` |
| `fake(array $stubs = [])` | `Testing\FakeClient` |
| `isFaked()` | `bool` |
| `recorded()` | `Collection<RecordedRequest>` |
| `assertSent(string\|Closure $matcher)` | `void` |
| `assertNotSent(string\|Closure $matcher)` | `void` |
| `assertNothingSent()` | `void` |
| `assertSentCount(int $count)` | `void` |

---

## Resources

### `PaymentResource` — `Plorea::payments()`

| Method | Returns |
| --- | --- |
| `link(string $reference, string $product, Amount $amount, string $returnUrl)` | `PendingPaymentLink` |
| `status(string $reference)` | `Data\PaymentStatus` |
| `refund(string $reference, string $modificationReference, Amount\|int\|null $amount = null, ?string $reason = null)` | `Data\Refund` |
| `cancel(string $reference, string $modificationReference)` | `Data\PaymentCancellation` |

`refund()` refunds the full amount when `$amount` is omitted.

### `PaymentMethodResource` — `Plorea::paymentMethods()`

| Method | Returns |
| --- | --- |
| `setup(string $shopperReference, RecurringType $recurringType, string $returnUrl)` | `PendingPaymentMethodSetup` |
| `find(string $paymentMethodId)` | `Data\PaymentMethod` |

### `SubscriptionResource` — `Plorea::subscriptions()`

| Method | Returns |
| --- | --- |
| `create(string $paymentMethodId, Amount $amount, BillingInterval $interval, RecurringType $recurringType = RecurringType::Subscription)` | `PendingSubscription` |
| `find(string $subscriptionId)` | `Data\Subscription` |
| `update(string $subscriptionId)` | `PendingSubscriptionUpdate` |
| `forExternalId(string $externalId, ?string $tenantId = null, ?string $status = null)` | `Collection<Data\Subscription>` |
| `needingAttention(string $externalId, ?string $tenantId = null, int $graceMinutes = 60)` | `Collection<Data\Subscription>` |
| `charge(string $subscriptionId, ?Amount $amount = null, ?string $reason = null, ?float $vatRate = null, ?int $vatAmount = null)` | `Data\SubscriptionCharge` |
| `charges(string $subscriptionId)` | `Collection<Data\SubscriptionCharge>` |
| `cancel(string $subscriptionId, ?string $reason = null)` | `Data\SubscriptionCancellation` |
| `reactivate(string $subscriptionId)` | `Data\Subscription` |

`charges()` is newest-first. `charge()` charges the subscription amount when
`$amount` is omitted. `needingAttention()` is the polling half of dunning — see
[the dunning gap](subscriptions.md#the-dunning-gap). Neither list method has
been observed to paginate, and neither sends paging parameters; see
[the open question](api-behaviour.md#-neither-list-endpoint-has-been-seen-to-paginate).

### `PayByLinkResource` — `Plorea::payByLink()`

| Method | Returns |
| --- | --- |
| `find(string $paymentLinkId)` | `Data\PaymentLink` |
| `session(string $paymentLinkId, ?string $returnUrl = null, ?Channel $channel = null)` | `Data\PaymentSession` |

---

## Pending builders

Builder methods mutate the builder and return `$this` for chaining. Nothing is
sent until the terminal method.

### `PendingPaymentLink`

| Method | Notes |
| --- | --- |
| `tenant(string $tenantId)` | Overrides the configured tenant |
| `platform(string $platform)` | |
| `orderId(string $orderId)` | |
| `doneId(string $doneId)` | |
| `payerEmail(string $email)` | |
| `invoiceUrl(string $url)` | |
| `merchant(string $orgNr, ?string $name = null, ?string $email = null, ?string $phone = null, ?string $country = null)` | **Required** — the invoice issuer's org number |
| `enableSplits(bool $enabled = true)` | |
| `store(string $store)` | **Deprecated** — Plorea resolves the store from the org number |
| `toPayload()` | `array` — the request body, for inspection |
| **`create()`** | → `Data\PaymentLinkCreated`. Throws `PloreaException` without a merchant org number |
| **`firstOrCreate(int $maxAttempts = 10)`** | → `Data\PaymentLinkCreated`. Throws `PaymentAlreadyPaidException` |

### `PendingPaymentMethodSetup`

| Method | Notes |
| --- | --- |
| `tenant(string $tenantId)` | |
| `customerId(string $customerId)` | Your own customer id |
| `doneId(string $doneId)` | |
| `description(string $description)` | |
| `metadata(array $metadata)` | |
| **`create()`** | → `Data\PaymentMethod` (hosted redirect) |
| `channel(Channel $channel)` | Drop-in session flow only. Unset, nothing is sent (Plorea treats it as `Web`) |
| **`session()`** | → `Data\PaymentMethodSession` (Adyen Drop-in) |

### `PendingSubscription`

| Method | Notes |
| --- | --- |
| `tenant(string $tenantId)` | |
| `customerId(string $customerId)` | |
| `doneId(string $doneId)` | |
| `externalId(string $externalId)` | Your own entity id — set this |
| `title(string $title)` | |
| `description(string $description)` | |
| `quantity(int $quantity)` | |
| `vat(float $rate, int $amount)` | Rate as a fraction (`0.25`), amount in minor units |
| `trialUntil(DateTimeInterface $trialEndsAt)` | Creates the subscription `trialing` |
| `retryPolicy(RetryPolicy\|int $policy, int $retryIntervalDays = 1)` | |
| `metadata(array $metadata)` | |
| `toPayload()` | `array` |
| **`save()`** | → `Data\Subscription` |

### `PendingSubscriptionUpdate`

| Method | Notes |
| --- | --- |
| `externalId(string $externalId)` | |
| `title(string $title)` | |
| `description(string $description)` | |
| `amount(Amount $amount)` | |
| `quantity(int $quantity)` | |
| `vat(float $rate, int $amount)` | |
| `paymentMethod(string $paymentMethodId)` | Must be an `active` method |
| **`save()`** | → `Data\Subscription` |

Only the fields you set are sent. The billing interval cannot be changed.

---

## Data objects

Every DTO is `final readonly`, is built by `fromArray()`, and keeps the
untouched response in `->raw`. Dates are `CarbonImmutable` and parse to `null`
rather than throwing when malformed.

### `Amount`

| Member | Type |
| --- | --- |
| `$value` | `int` — **minor units** |
| `$currency` | `string` |
| `Amount::nok(int $ore)` | `self` |
| `inMajorUnits()` | `float` |
| `formatted(?string $locale = null)` | `string` |
| `toArray()` | `array` |

### `BillingInterval`

| Member | Type |
| --- | --- |
| `$unit` | `Enums\IntervalUnit` |
| `$count` | `int` |
| `daily()` / `weekly()` / `monthly()` / `yearly()` | `self` — each takes `int $count = 1` |

### `RetryPolicy`

`$maxRetries`, `$retryIntervalDays` — both `int`.

### `PaymentLinkCreated` — returned by `create()` / `firstOrCreate()`

`$id`, `$url`, `$reference`, `$status`, `$environment`, `$tenantId`, `$doneId`,
`$merchantAccount`, `$store`, `$balanceAccountId`, `$splitsEnabled`,
`$provider`, `$expiresAt`, `$partnerSplitsApplied`, `$raw`.

Note: the merchant org number is **not** echoed here — read it from the pay page.

### `PaymentLink` — the pay page, `payByLink()->find()`

`$id`, `$tenantId`, `$reference`, `$product`, `$amount`, `$environment`,
`$merchantName`, `$merchantOrgNr`, `$returnUrl`, `$invoiceUrl`, `$expiresAt`,
`$expired` (`bool` — Plorea's computed answer, the authoritative expiry
signal), `$raw`.

### `PaymentStatus`

| Property | Type |
| --- | --- |
| `$reference`, `$status`, `$platform`, `$orderId`, `$doneId`, `$provider`, `$pspReference` | `string` / `?string` |
| `$amount` | `?Amount` |
| `$paymentLinkId`, `$paymentLinkUrl`, `$tenantId`, `$environment`, `$merchantAccount`, `$balanceAccountId`, `$store` | `?string` |
| `$splitsEnabled` | `?bool` |
| `$createdAt`, `$updatedAt` | `?CarbonImmutable` |
| `$webhookEventCode`, `$webhookSuccess`, `$lastWebhookAt` | Plorea's **inbound** Adyen webhook |
| `$lastRefundReference`, `$lastRefundRequestPspReference`, `$lastRefundAmount`, `$lastRefundReason`, `$lastRefundRequestedAt` | Last refund |
| `$lastCancelReference`, `$lastCancelRequestPspReference`, `$lastCancelRequestedAt` | Last cancellation |

| Helper | True for |
| --- | --- |
| `isPaid()` | `authorised`, `paid` |
| `isAuthorised()` | `authorised` |
| `isOpen()` | `created`, `pending`, `active` |
| `isRefundRequested()` | `refund_requested` |
| `isCancelRequested()` | `cancel_requested` |
| `is(string $status)` | Exact match on the raw string |

A refusal is `failed` — use `is('failed')`. There is no `failureReason` field
on this shape.

### `Refund`

`$status`, `$reference`, `$modificationReference`, `$refundPspReference`,
`$paymentPspReference`, `$amount`, `$environment`, `$raw`.

### `PaymentCancellation`

`$status`, `$reference`, `$modificationReference`, `$cancelPspReference`,
`$paymentPspReference`, `$environment`, `$raw`.

### `PaymentSession` — Drop-in for a payment link

`$sessionId`, `$sessionData`, `$clientKey`, `$environment`, `$raw`.

`$clientKey` is the response's key if it has one, else `plorea.adyen_client_key`, which must match `plorea.environment`.
`$environment` is the response's, else `plorea.environment`. `toCheckout()`
returns `sessionId`, `sessionData`, `clientKey` and `environment`, and the DTO
serializes to exactly that (`JsonSerializable`), so `raw` never reaches a
client.

### `PaymentMethod`

| Property | Notes |
| --- | --- |
| `$id`, `$tenantId`, `$customerId`, `$doneId`, `$shopperReference` | |
| `$recurringType` | `?Enums\RecurringType` |
| `$status` | `pending_setup` \| `active` \| `failed` |
| `$adyenReference`, `$adyenPaymentLinkId`, `$adyenPaymentLinkUrl` | Hosted setup |
| `$storedPaymentMethodId` | Adyen's token — null until `active` |
| `$setupPspReference` | |
| `$cardLast4`, `$cardBrand`, `$expiryDate` | Populated once `active` |
| `$consentAt`, `$expiresAt`, `$createdAt`, `$updatedAt` | `?CarbonImmutable` |
| `$metadata`, `$raw` | `array` |

Helpers: `isActive()`, `isPendingSetup()`, `hasFailed()`, `is(string $status)`.

### `PaymentMethodSession`

`$paymentMethodId`, `$sessionId`, `$sessionData`, `$tenantId`, `$customerId`,
`$doneId`, `$shopperReference`, `$recurringType`, `$status`, `$environment`,
`$expiresAt`, `$clientKey`, `$channel` (`?Channel`, as echoed by Plorea), `$raw`.

`$clientKey`, `$environment`, `toCheckout()` and JSON serialization behave as on
`PaymentSession`.

### `Subscription`

| Property | Notes |
| --- | --- |
| `$id`, `$tenantId`, `$customerId`, `$doneId` | |
| `$paymentMethodId`, `$shopperReference`, `$storedPaymentMethodId` | |
| `$recurringType` | `?RecurringType` |
| `$amount` | `?Amount` |
| `$quantity`, `$vatRate`, `$vatAmount` | |
| `$externalId`, `$title`, `$description` | |
| `$interval` | `?BillingInterval` |
| `$status` | `active` \| `trialing` \| `canceled` \| (`payment_failed`, unobserved) |
| `$trialEndsAt`, `$nextChargeAt`, `$lastChargeAt` | `?CarbonImmutable` |
| `$lastPaymentReference` | `{subId}-{chgId}` — resolvable as a payment for **manual** charges only |
| `$retryPolicy`, `$retryCount`, `$failureReason` | Dunning — see [known limitations](api-behaviour.md#what-cannot-be-reproduced-in-test) |
| `$canceledAt`, `$cancelReason`, `$accessEndsAt` | `accessEndsAt` is null when cancelling a trial |
| `$metadata`, `$createdAt`, `$updatedAt`, `$raw` | |

Helpers: `isActive()`, `isTrialing()`, `isCanceled()`, `hasPaymentFailure()`,
`is(string $status)`, and
`isOverdue(int $graceMinutes = 60, ?DateTimeInterface $now = null)` — a
scheduled charge that has not landed. A canceled subscription is never
overdue.

### `SubscriptionCharge`

`$id`, `$subscriptionId`, `$tenantId`, `$reference`, `$pspReference`,
`$status`, `$resultCode`, `$amount`, `$vatRate`, `$vatAmount`, `$retryNumber`,
`$failureReason`, `$reason` (`manual_charge` \| `scheduled_charge`),
`$nextChargeAt`, `$createdAt`, `$updatedAt`, `$raw`.

On the immediate response `status` is `charge_created` and `resultCode` holds
Adyen's answer; in `charges()` history `status` is the settled value.

Helpers: `is(string $status)`, `isAuthorised()`. A freshly created charge is
not authorised yet — read it back from `charges()`.

### `SubscriptionCancellation`

`$subscriptionId`, `$status`, `$canceledAt`, `$accessEndsAt`, `$reason`, `$raw`.

---

## Enums

| Enum | Cases |
| --- | --- |
| `Enums\Channel` | `Web` = `Web`, `IOS` = `iOS`, `Android` = `Android` — plus `isNative()` |
| `Enums\Environment` | `Test` = `test`, `Live` = `live` — plus `isLive()` |
| `Enums\IntervalUnit` | `Day`, `Week`, `Month`, `Year` |
| `Enums\RecurringType` | `Subscription`, `CardOnFile`, `UnscheduledCardOnFile` |

---

## Events

| Event | Properties |
| --- | --- |
| `Events\PaymentStatusUpdated` | `$reference`, `$status`, `$payload`, `$eventId`, `$type` |
| `Events\SubscriptionChargeSucceeded` | `$subscriptionId`, `$chargeId`, `$reference`, `$externalId`, `$payload`, `$eventId`, `$type` |
| `Events\WebhookReceived` | `$payload`, `$eventId`, `$type` — dispatched for **every** delivery |
| `Events\RequestSent` | `$method`, `$uri`, `$payload` |
| `Events\ResponseReceived` | `$method`, `$uri`, `$payload`, `$status`, `$response`, `$durationMs` |

No event carries the API key or any headers.

`$eventId` and `$type` on the three webhook events are read from the request
**body**, which is the only part the signature covers. Deduplicate on
`$event->eventId`, never on the `x-plorea-event-id` header — see
[Webhooks](webhooks.md#payload-shape).

---

## Exceptions

All extend `Exceptions\PloreaException`.

| Exception | HTTP |
| --- | --- |
| `ValidationException` | 400 |
| `AuthenticationException` | 401 / 403 |
| `ChargeFailedException` | 402 |
| `NotFoundException` | 404 |
| `ServerException` | 5xx |
| `ConnectionException` | — (unreachable) |
| `PaymentAlreadyPaidException` | — (SDK guard; carries `$status`) |

The HTTP ones extend `RequestException`, exposing `$status` and `$response`.

---

## Testing

| Class | Purpose |
| --- | --- |
| `Testing\FakeClient` | The in-memory client. `stub(array $stubs)` adds stubs |
| `Testing\RecordedRequest` | `$method`, `$path`, `$data`, `input($key, $default)`, `matches($pattern)` |
| `Testing\DefaultFixtures` | Default responses per route |

See [Testing](testing.md).
