<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Data;

use MemberFlow\Plorea\Data\Concerns\ParsesResponseData;

/**
 * Whether a payment can be refunded, and by how much: the `refundable` block
 * of a payment status.
 *
 * Captured in test 2026-11-09 on payment links and subscription charges.
 * `canRequest` was false with a `reason` for a fully refunded payment and
 * for a cancelled link. `mayStillFail` holds a warning even when
 * `canRequest` is true: a payment settled to a sub-merchant is refused with
 * 409 when its balance account cannot be resolved.
 */
final readonly class Refundability
{
    use ParsesResponseData;

    /**
     * @param  array<string, mixed>  $raw
     * @param  ?string  $reason  Why a refund cannot be requested. Null when it can.
     * @param  ?string  $mayStillFail  A warning about a refund that is allowed but can still be refused.
     */
    public function __construct(
        public ?Amount $originalAmount,
        public ?Amount $refundedTotal,
        public ?Amount $remainingAmount,
        public bool $canRequest,
        public ?string $reason,
        public ?string $mayStillFail,
        public array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): ?self
    {
        if ($data === null) {
            return null;
        }

        $currency = self::string($data['currency'] ?? null) ?? 'NOK';
        $amount = static fn (mixed $value): ?Amount => is_numeric($value) ? new Amount((int) $value, $currency) : null;

        return new self(
            originalAmount: $amount($data['originalAmount'] ?? null),
            refundedTotal: $amount($data['refundedTotal'] ?? null),
            remainingAmount: $amount($data['remainingAmount'] ?? null),
            canRequest: self::bool($data['canRequest'] ?? null) ?? false,
            reason: self::string($data['reason'] ?? null),
            mayStillFail: self::string($data['mayStillFail'] ?? null),
            raw: $data,
        );
    }
}
