<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Data;

use Carbon\CarbonImmutable;
use MemberFlow\Plorea\Data\Concerns\ParsesResponseData;

/**
 * The result of a payment cancellation request.
 */
final readonly class PaymentCancellation
{
    use ParsesResponseData;

    /**
     * @param  array<string, mixed>  $raw
     * @param  ?string  $subscriptionId  Set when the cancel is for a subscription charge. Seen in test 2026-10-19.
     * @param  ?string  $chargeId  Set when the cancel is for a subscription charge. Seen in test 2026-10-19.
     * @param  ?string  $paymentLinkId  Set when an unpaid payment link was withdrawn. Seen in test 2026-11-09.
     * @param  ?CarbonImmutable  $cancelledAt  When the unpaid link was withdrawn. A repeat cancel returns the first moment again.
     * @param  bool  $alreadyCancelled  True when the link had been withdrawn before. Plorea sends no second `link.cancelled` event then.
     */
    public function __construct(
        public ?string $status,
        public string $reference,
        public ?string $modificationReference,
        public ?string $cancelPspReference,
        public ?string $paymentPspReference,
        public ?string $environment,
        public array $raw = [],
        public ?string $subscriptionId = null,
        public ?string $chargeId = null,
        public ?string $paymentLinkId = null,
        public ?string $orderId = null,
        public ?Amount $amount = null,
        public ?CarbonImmutable $cancelledAt = null,
        public bool $alreadyCancelled = false,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $value = self::int($data['amount'] ?? null);

        return new self(
            status: self::string($data['status'] ?? null),
            reference: self::string($data['reference'] ?? null) ?? '',
            modificationReference: self::string($data['modificationReference'] ?? null),
            cancelPspReference: self::string($data['cancelPspReference'] ?? null),
            paymentPspReference: self::string($data['paymentPspReference'] ?? null),
            environment: self::string($data['environment'] ?? null),
            raw: $data,
            subscriptionId: self::string($data['subscriptionId'] ?? null),
            chargeId: self::string($data['chargeId'] ?? null),
            paymentLinkId: self::string($data['paymentLinkId'] ?? null),
            orderId: self::string($data['orderId'] ?? null),
            amount: $value === null ? null : new Amount($value, self::string($data['currency'] ?? null) ?? 'NOK'),
            cancelledAt: self::date($data['cancelledAt'] ?? null),
            alreadyCancelled: self::bool($data['alreadyCancelled'] ?? null) ?? false,
        );
    }

    /**
     * Whether an unpaid payment link was withdrawn (as opposed to an
     * authorisation sent to Adyen for cancelling). Verified in test
     * 2026-11-09: the link answers 410 from then on and its status reads
     * `cancelled`.
     */
    public function isLinkWithdrawn(): bool
    {
        return $this->status !== null && strcasecmp($this->status, 'cancelled') === 0
            && $this->paymentLinkId !== null;
    }
}
