<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Mcp;

use BackedEnum;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Arrayable;
use MemberFlow\Plorea\Data\PaymentLink;
use MemberFlow\Plorea\Data\PaymentMethod;
use MemberFlow\Plorea\Data\PaymentRefund;
use MemberFlow\Plorea\Data\PaymentStatus;
use MemberFlow\Plorea\Data\Subscription;
use MemberFlow\Plorea\Data\SubscriptionCharge;

/**
 * Turns an SDK DTO into the array an agent may see.
 *
 * Every DTO has an allowlist. A field marked "keep" is shown as it is. A
 * field marked "mask" is shown as **** when masking is on, and null stays
 * null so the agent still sees whether the field is set. A field that is not
 * on the list, `raw` included, is never shown: a new field stays hidden
 * until someone classifies it. MaskerTest fails when a DTO has a field that
 * is not on its list.
 *
 * Text and ids the app chooses are masked too (externalId, orderId, title,
 * product, platform, store, refund and cancel references): an app may put
 * an e-mail or a name in them. The payment reference is kept, because an agent needs it to
 * follow a payment.
 */
class Masker
{
    public const string MASK = '****';

    /**
     * @var array<class-string, array<string, 'keep'|'mask'>>
     */
    public const array FIELDS = [
        PaymentStatus::class => [
            'reference' => 'keep',
            'tenantId' => 'keep',
            'platform' => 'mask',
            'orderId' => 'mask',
            'doneId' => 'mask',
            'status' => 'keep',
            'provider' => 'keep',
            'pspReference' => 'keep',
            'paymentLinkId' => 'keep',
            'paymentLinkUrl' => 'mask',
            'amount' => 'keep',
            'environment' => 'keep',
            'merchantAccount' => 'keep',
            'balanceAccountId' => 'keep',
            'store' => 'mask',
            'splitsEnabled' => 'keep',
            'createdAt' => 'keep',
            'updatedAt' => 'keep',
            'webhookEventCode' => 'keep',
            'webhookSuccess' => 'keep',
            'lastWebhookAt' => 'keep',
            'lastRefundReference' => 'mask',
            'lastRefundRequestPspReference' => 'keep',
            'lastRefundAmount' => 'keep',
            'lastRefundReason' => 'mask',
            'lastRefundRequestedAt' => 'keep',
            'lastCancelReference' => 'mask',
            'lastCancelRequestPspReference' => 'keep',
            'lastCancelRequestedAt' => 'keep',
            'subscriptionId' => 'keep',
            'chargeId' => 'keep',
            'refundedTotal' => 'keep',
            'refundStatus' => 'keep',
            'refunds' => 'keep',
            'cancelStatus' => 'keep',
            'cancelReference' => 'mask',
            'cancelPspReference' => 'keep',
            'cancelRequestedAt' => 'keep',
        ],
        PaymentRefund::class => [
            'amount' => 'keep',
            'reason' => 'mask',
            'refundPspReference' => 'keep',
            'reference' => 'mask',
            'requestedAt' => 'keep',
            'settledAt' => 'keep',
            'source' => 'keep',
            'status' => 'keep',
        ],
        PaymentLink::class => [
            'id' => 'keep',
            'tenantId' => 'keep',
            'reference' => 'keep',
            'product' => 'mask',
            'amount' => 'keep',
            'environment' => 'keep',
            'merchantName' => 'mask',
            'merchantOrgNr' => 'mask',
            'returnUrl' => 'mask',
            'invoiceUrl' => 'mask',
            'expiresAt' => 'keep',
            'expired' => 'keep',
        ],
        Subscription::class => [
            'id' => 'keep',
            'tenantId' => 'keep',
            'customerId' => 'mask',
            'doneId' => 'mask',
            'paymentMethodId' => 'keep',
            'shopperReference' => 'mask',
            'storedPaymentMethodId' => 'mask',
            'recurringType' => 'keep',
            'amount' => 'keep',
            'quantity' => 'keep',
            'externalId' => 'mask',
            'title' => 'mask',
            'description' => 'mask',
            'vatRate' => 'keep',
            'vatAmount' => 'keep',
            'interval' => 'keep',
            'trialEndsAt' => 'keep',
            'nextChargeAt' => 'keep',
            'retryPolicy' => 'keep',
            'retryCount' => 'keep',
            'lastChargeAt' => 'keep',
            'lastPaymentReference' => 'keep',
            'failureReason' => 'keep',
            'canceledAt' => 'keep',
            'cancelReason' => 'mask',
            'accessEndsAt' => 'keep',
            'status' => 'keep',
            'metadata' => 'mask',
            'createdAt' => 'keep',
            'updatedAt' => 'keep',
            'merchantOrgNr' => 'mask',
            'environment' => 'keep',
            'currentPeriodEnd' => 'keep',
        ],
        SubscriptionCharge::class => [
            'id' => 'keep',
            'subscriptionId' => 'keep',
            'tenantId' => 'keep',
            'reference' => 'keep',
            'pspReference' => 'keep',
            'status' => 'keep',
            'resultCode' => 'keep',
            'amount' => 'keep',
            'vatRate' => 'keep',
            'vatAmount' => 'keep',
            'retryNumber' => 'keep',
            'failureReason' => 'keep',
            'reason' => 'keep',
            'nextChargeAt' => 'keep',
            'createdAt' => 'keep',
            'updatedAt' => 'keep',
        ],
        PaymentMethod::class => [
            'id' => 'keep',
            'tenantId' => 'keep',
            'customerId' => 'mask',
            'doneId' => 'mask',
            'shopperReference' => 'mask',
            'recurringType' => 'keep',
            'environment' => 'keep',
            'status' => 'keep',
            'adyenReference' => 'keep',
            'adyenPaymentLinkId' => 'keep',
            'adyenPaymentLinkUrl' => 'mask',
            'storedPaymentMethodId' => 'mask',
            'setupPspReference' => 'keep',
            'cardLast4' => 'mask',
            'cardBrand' => 'keep',
            'expiryDate' => 'mask',
            'consentAt' => 'keep',
            'expiresAt' => 'keep',
            'metadata' => 'mask',
            'createdAt' => 'keep',
            'updatedAt' => 'keep',
            'last4' => 'mask',
            'brand' => 'keep',
            'expiryMonth' => 'mask',
            'expiryYear' => 'mask',
            'fundingSource' => 'keep',
            'walletType' => 'keep',
            'isToken' => 'keep',
            'tokenLast4' => 'mask',
            'physicalCardLast4' => 'mask',
            'physicalCardExpiryMonth' => 'mask',
            'physicalCardExpiryYear' => 'mask',
        ],
    ];

    public function __construct(protected bool $masking = true) {}

    /**
     * @return array<string, mixed>
     */
    public function present(object $dto): array
    {
        $output = [];

        foreach (self::FIELDS[$dto::class] ?? [] as $field => $rule) {
            $value = $dto->{$field} ?? null;

            $output[$field] = $rule === 'mask' && $this->masking && $value !== null && $value !== []
                ? self::MASK
                : $this->value($value);
        }

        return $output;
    }

    protected function value(mixed $value): mixed
    {
        return match (true) {
            $value instanceof CarbonImmutable => $value->toIso8601ZuluString('millisecond'),
            $value instanceof BackedEnum => $value->value,
            $value instanceof Arrayable => $value->toArray(),
            is_array($value) => array_map($this->value(...), $value),
            is_object($value) => $this->present($value),
            default => $value,
        };
    }
}
