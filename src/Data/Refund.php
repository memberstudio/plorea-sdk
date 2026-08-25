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
            refundPspReference: self::string($data['refundPspReference'] ?? null),
            paymentPspReference: self::string($data['paymentPspReference'] ?? null),
            amount: $value === null ? null : new Amount($value, self::string($data['currency'] ?? null) ?? 'NOK'),
            environment: self::string($data['environment'] ?? null),
            raw: $data,
        );
    }
}
