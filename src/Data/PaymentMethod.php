<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Data;

use Carbon\CarbonImmutable;
use MemberFlow\Plorea\Data\Concerns\ParsesResponseData;
use MemberFlow\Plorea\Enums\RecurringType;

/**
 * A stored payment method used for future recurring charges.
 */
final readonly class PaymentMethod
{
    use ParsesResponseData;

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $raw
     * @param  ?string  $last4  The card fields below were added on 2026-10-14. Verified in test that day on a read: all present, and the older cardLast4, cardBrand and expiryDate remain. Methods stored before then have them only where Adyen sent the data, so any can be null.
     * @param  ?string  $fundingSource  For example "credit" or "debit". Null on a Visa test card.
     * @param  ?string  $walletType  Set for a wallet such as Apple Pay or Google Pay. Null on a plain card.
     * @param  ?bool  $isToken  Whether the stored card is a network token. Null on a Visa test card.
     * @param  ?string  $tokenLast4  The last digits of the network token, which the customer does not recognise.
     * @param  ?string  $physicalCardLast4  The digits printed on the customer's card. Show these.
     */
    public function __construct(
        public string $id,
        public ?string $tenantId,
        public ?string $customerId,
        public ?string $doneId,
        public ?string $shopperReference,
        public ?RecurringType $recurringType,
        public ?string $environment,
        public ?string $status,
        public ?string $adyenReference,
        public ?string $adyenPaymentLinkId,
        public ?string $adyenPaymentLinkUrl,
        public ?string $storedPaymentMethodId,
        public ?string $setupPspReference,
        public ?string $cardLast4,
        public ?string $cardBrand,
        public ?string $expiryDate,
        public ?CarbonImmutable $consentAt,
        public ?CarbonImmutable $expiresAt,
        public array $metadata,
        public ?CarbonImmutable $createdAt,
        public ?CarbonImmutable $updatedAt,
        public array $raw = [],
        public ?string $last4 = null,
        public ?string $brand = null,
        public ?int $expiryMonth = null,
        public ?int $expiryYear = null,
        public ?string $fundingSource = null,
        public ?string $walletType = null,
        public ?bool $isToken = null,
        public ?string $tokenLast4 = null,
        public ?string $physicalCardLast4 = null,
        public ?int $physicalCardExpiryMonth = null,
        public ?int $physicalCardExpiryYear = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: self::string($data['paymentMethodId'] ?? null) ?? '',
            tenantId: self::string($data['tenantId'] ?? null),
            customerId: self::string($data['customerId'] ?? null),
            doneId: self::string($data['doneId'] ?? null),
            shopperReference: self::string($data['shopperReference'] ?? null),
            recurringType: RecurringType::tryFrom(self::string($data['recurringType'] ?? null) ?? ''),
            environment: self::string($data['environment'] ?? null),
            status: self::string($data['status'] ?? null),
            adyenReference: self::string($data['adyenReference'] ?? null),
            adyenPaymentLinkId: self::string($data['adyenPaymentLinkId'] ?? null),
            adyenPaymentLinkUrl: self::string($data['adyenPaymentLinkUrl'] ?? null),
            storedPaymentMethodId: self::string($data['storedPaymentMethodId'] ?? null),
            setupPspReference: self::string($data['setupPspReference'] ?? null),
            cardLast4: self::string($data['cardLast4'] ?? null),
            cardBrand: self::string($data['cardBrand'] ?? null),
            expiryDate: self::string($data['expiryDate'] ?? null),
            consentAt: self::date($data['consentAt'] ?? null),
            expiresAt: self::date($data['expiresAt'] ?? null),
            metadata: self::metadata($data['metadata'] ?? null),
            createdAt: self::date($data['createdAt'] ?? null),
            updatedAt: self::date($data['updatedAt'] ?? null),
            raw: $data,
            last4: self::string($data['last4'] ?? null),
            brand: self::string($data['brand'] ?? null),
            expiryMonth: self::int($data['expiryMonth'] ?? null),
            expiryYear: self::int($data['expiryYear'] ?? null),
            fundingSource: self::string($data['fundingSource'] ?? null),
            walletType: self::string($data['walletType'] ?? null),
            isToken: self::bool($data['isToken'] ?? null),
            tokenLast4: self::string($data['tokenLast4'] ?? null),
            physicalCardLast4: self::string($data['physicalCardLast4'] ?? null),
            physicalCardExpiryMonth: self::int($data['physicalCardExpiryMonth'] ?? null),
            physicalCardExpiryYear: self::int($data['physicalCardExpiryYear'] ?? null),
        );
    }

    /**
     * Whether the payment method status matches the given value.
     */
    public function is(string $status): bool
    {
        return $this->status !== null && strcasecmp($this->status, $status) === 0;
    }

    /**
     * Whether the payment method is active and can be charged.
     */
    public function isActive(): bool
    {
        return $this->is('active');
    }

    /**
     * Whether the customer has not finished the setup flow yet. Poll until
     * the status leaves this state.
     */
    public function isPendingSetup(): bool
    {
        return $this->is('pending_setup');
    }

    /**
     * Whether the setup failed — the verification authorisation Adyen makes
     * during setup was refused, so no card was stored. A failed method can
     * never be charged; start a new setup instead.
     */
    public function hasFailed(): bool
    {
        return $this->is('failed');
    }

    /**
     * Whether the payment method was deleted. A cancelled method is never
     * charged again and cannot be put on a subscription.
     */
    public function isCancelled(): bool
    {
        return $this->is('cancelled');
    }
}
