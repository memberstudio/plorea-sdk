<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Data;

use Carbon\CarbonImmutable;
use MemberFlow\Plorea\Data\Concerns\ParsesResponseData;

/**
 * A charge on a subscription — either the result of creating one manually,
 * or an item from the charge history.
 */
final readonly class SubscriptionCharge
{
    use ParsesResponseData;

    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $id,
        public ?string $subscriptionId,
        public ?string $tenantId,
        public ?string $reference,
        public ?string $pspReference,
        public ?string $status,
        public ?string $resultCode,
        public ?Amount $amount,
        public ?float $vatRate,
        public ?int $vatAmount,
        public ?int $retryNumber,
        public ?string $failureReason,
        public ?string $reason,
        public ?CarbonImmutable $nextChargeAt,
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
            id: self::string($data['chargeId'] ?? null) ?? '',
            subscriptionId: self::string($data['subscriptionId'] ?? null),
            tenantId: self::string($data['tenantId'] ?? null),
            reference: self::string($data['reference'] ?? null),
            pspReference: self::string($data['pspReference'] ?? null),
            status: self::string($data['status'] ?? null),
            resultCode: self::string($data['resultCode'] ?? null),
            amount: Amount::fromArray(is_array($data['amount'] ?? null) ? $data['amount'] : null),
            vatRate: self::float($data['vatRate'] ?? null),
            vatAmount: self::int($data['vatAmount'] ?? null),
            retryNumber: self::int($data['retryNumber'] ?? null),
            failureReason: self::string($data['failureReason'] ?? null),
            reason: self::string($data['reason'] ?? null),
            nextChargeAt: self::date($data['nextChargeAt'] ?? null),
            createdAt: self::date($data['createdAt'] ?? null),
            updatedAt: self::date($data['updatedAt'] ?? null),
            raw: $data,
        );
    }
}
