<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Data;

use Carbon\CarbonImmutable;
use MemberFlow\Plorea\Data\Concerns\ParsesResponseData;

/**
 * One refund in a payment status's `refunds` list.
 *
 * Seen on a subscription charge's status in test, 2026-10-19: the refund was
 * `refunded` with a `settledAt` 34 seconds after `requestedAt`.
 */
final readonly class PaymentRefund
{
    use ParsesResponseData;

    /**
     * @param  array<string, mixed>  $raw
     * @param  ?string  $reference  The modification reference sent with the refund (`refundReference` on the wire).
     * @param  ?string  $source  Where the refund came from. `api` (test 2026-10-19) and `adyen` (test 2026-11-09) have been seen.
     * @param  ?string  $eventCode  Adyen's event code, for example `REFUND`. Seen in test 2026-11-09.
     */
    public function __construct(
        public ?Amount $amount,
        public ?string $reason,
        public ?string $refundPspReference,
        public ?string $reference,
        public ?CarbonImmutable $requestedAt,
        public ?CarbonImmutable $settledAt,
        public ?string $source,
        public ?string $status,
        public array $raw = [],
        public ?string $eventCode = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $value = self::int($data['amount'] ?? null);

        return new self(
            amount: $value === null ? null : new Amount($value, self::string($data['currency'] ?? null) ?? 'NOK'),
            reason: self::string($data['reason'] ?? null),
            refundPspReference: self::string($data['refundPspReference'] ?? null),
            reference: self::string($data['refundReference'] ?? null),
            requestedAt: self::date($data['requestedAt'] ?? null),
            settledAt: self::date($data['settledAt'] ?? null),
            source: self::string($data['source'] ?? null),
            status: self::string($data['status'] ?? null),
            raw: $data,
            eventCode: self::string($data['eventCode'] ?? null),
        );
    }

    /**
     * Whether Adyen settled the refund.
     */
    public function isRefunded(): bool
    {
        return $this->status !== null && strcasecmp($this->status, 'refunded') === 0;
    }

    /**
     * Whether Adyen refused the refund. Seen in test 2026-11-09 as
     * `refund_failed`; the payment's `refundStatus` said the same.
     */
    public function isFailed(): bool
    {
        return $this->status !== null && strcasecmp($this->status, 'refund_failed') === 0;
    }
}
