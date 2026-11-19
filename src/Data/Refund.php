<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Data;

use MemberFlow\Plorea\Data\Concerns\ParsesResponseData;

/**
 * The result of a refund request.
 */
final readonly class Refund
{
    use ParsesResponseData;

    /**
     * @param  array<string, mixed>  $raw
     * @param  ?string  $subscriptionId  Set when the refund is for a subscription charge. Seen in test 2026-10-19.
     * @param  ?string  $chargeId  Set when the refund is for a subscription charge. Seen in test 2026-10-19.
     * @param  ?Amount  $refundedTotal  The total refunded on the payment, this refund included. Seen on a subscription charge in test 2026-10-19.
     * @param  ?string  $refundStatus  For example `partially_refunded`. Seen on a subscription charge in test 2026-10-19.
     * @param  ?array<int, mixed>  $splits  The split lines the refund was sent with. Null for a payment without splits (test 2026-11-09); a split refund is unobserved.
     * @param  ?string  $splitsSource  Where the split lines came from: `link`, `charge`, `rebuilt` or `none`. Only `none` has been seen (test 2026-11-09).
     * @param  ?string  $adyenStatus  Adyen's answer to the request, `received` in test 2026-11-09. Not the outcome: that arrives later on the status.
     */
    public function __construct(
        public ?string $status,
        public string $reference,
        public ?string $modificationReference,
        public ?string $refundPspReference,
        public ?string $paymentPspReference,
        public ?Amount $amount,
        public ?string $environment,
        public array $raw = [],
        public ?string $subscriptionId = null,
        public ?string $chargeId = null,
        public ?Amount $refundedTotal = null,
        public ?string $refundStatus = null,
        public ?array $splits = null,
        public ?string $splitsSource = null,
        public ?string $adyenStatus = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $value = self::int($data['amount'] ?? null);
        $currency = self::string($data['currency'] ?? null) ?? 'NOK';
        $refundedTotal = self::int($data['refundedTotal'] ?? null);

        return new self(
            status: self::string($data['status'] ?? null),
            reference: self::string($data['reference'] ?? null) ?? '',
            modificationReference: self::string($data['modificationReference'] ?? null),
            refundPspReference: self::string($data['refundPspReference'] ?? null),
            paymentPspReference: self::string($data['paymentPspReference'] ?? null),
            amount: $value === null ? null : new Amount($value, $currency),
            environment: self::string($data['environment'] ?? null),
            raw: $data,
            subscriptionId: self::string($data['subscriptionId'] ?? null),
            chargeId: self::string($data['chargeId'] ?? null),
            refundedTotal: $refundedTotal === null ? null : new Amount($refundedTotal, $currency),
            refundStatus: self::string($data['refundStatus'] ?? null),
            splits: is_array($data['splits'] ?? null) ? $data['splits'] : null,
            splitsSource: self::string($data['splitsSource'] ?? null),
            adyenStatus: self::string($data['adyenStatus'] ?? null),
        );
    }
}
