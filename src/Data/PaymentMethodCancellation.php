<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Data;

use Carbon\CarbonImmutable;
use MemberFlow\Plorea\Data\Concerns\ParsesResponseData;

/**
 * The result of deleting (cancelling) a stored payment method.
 *
 * Plorea keeps the record and moves it to `cancelled` (UK spelling, unlike a
 * subscription's `canceled`). Deleting an already cancelled method succeeds
 * again with `alreadyCancelled` set and no `previousStatus` or `cancelledAt`.
 */
final readonly class PaymentMethodCancellation
{
    use ParsesResponseData;

    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $paymentMethodId,
        public ?string $status,
        public ?string $previousStatus,
        public bool $alreadyCancelled,
        public ?CarbonImmutable $cancelledAt,
        public ?CarbonImmutable $updatedAt,
        public array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            paymentMethodId: self::string($data['paymentMethodId'] ?? null) ?? '',
            status: self::string($data['status'] ?? null),
            previousStatus: self::string($data['previousStatus'] ?? null),
            alreadyCancelled: self::bool($data['alreadyCancelled'] ?? null) ?? false,
            cancelledAt: self::date($data['cancelledAt'] ?? null),
            updatedAt: self::date($data['updatedAt'] ?? null),
            raw: $data,
        );
    }

    /**
     * Whether the payment method is cancelled — by this call or an earlier one.
     */
    public function isCancelled(): bool
    {
        return $this->status !== null && strcasecmp($this->status, 'cancelled') === 0;
    }
}
