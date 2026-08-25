<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Data;

use MemberFlow\Plorea\Data\Concerns\ParsesResponseData;

/**
 * The result of a payment cancellation request.
 */
final readonly class PaymentCancellation
{
    use ParsesResponseData;

    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public ?string $status,
        public string $reference,
        public ?string $modificationReference,
        public ?string $cancelPspReference,
        public ?string $paymentPspReference,
        public ?string $environment,
        public array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            status: self::string($data['status'] ?? null),
            reference: self::string($data['reference'] ?? null) ?? '',
            modificationReference: self::string($data['modificationReference'] ?? null),
            cancelPspReference: self::string($data['cancelPspReference'] ?? null),
            paymentPspReference: self::string($data['paymentPspReference'] ?? null),
            environment: self::string($data['environment'] ?? null),
            raw: $data,
        );
    }
}
