<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Data;

use Carbon\CarbonImmutable;
use MemberFlow\Plorea\Data\Concerns\ParsesResponseData;

/**
 * The result of canceling a subscription.
 *
 * Access typically runs to the end of the paid period: `accessEndsAt` is one
 * billing interval after the last charge, not the cancellation time.
 */
final readonly class SubscriptionCancellation
{
    use ParsesResponseData;

    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $subscriptionId,
        public ?string $status,
        public ?CarbonImmutable $canceledAt,
        public ?CarbonImmutable $accessEndsAt,
        public ?string $reason,
        public array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            subscriptionId: self::string($data['subscriptionId'] ?? null) ?? '',
            status: self::string($data['status'] ?? null),
            canceledAt: self::date($data['canceledAt'] ?? null),
            accessEndsAt: self::date($data['accessEndsAt'] ?? null),
            reason: self::string($data['reason'] ?? null),
            raw: $data,
        );
    }
}
