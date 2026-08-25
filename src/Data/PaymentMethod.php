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
        );
    }

    /**
     * Whether the payment method is active and can be charged.
     */
    public function isActive(): bool
    {
        return $this->status !== null && strcasecmp($this->status, 'active') === 0;
    }
}
