<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Data;

use Carbon\CarbonImmutable;
use MemberFlow\Plorea\Data\Concerns\ParsesResponseData;

/**
 * A stored Plorea payment link, as rendered by pay.plorea.no.
 */
final readonly class PaymentLink
{
    use ParsesResponseData;

    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $id,
        public ?string $tenantId,
        public ?string $reference,
        public ?string $product,
        public ?Amount $amount,
        public ?string $environment,
        public ?string $merchantName,
        public ?string $merchantOrgNr,
        public ?string $returnUrl,
        public ?string $invoiceUrl,
        public ?CarbonImmutable $expiresAt,
        public bool $expired,
        public array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $value = self::int($data['amount'] ?? null);

        return new self(
            id: self::string($data['id'] ?? null) ?? '',
            tenantId: self::string($data['tenantId'] ?? null),
            reference: self::string($data['reference'] ?? null),
            product: self::string($data['product'] ?? null),
            amount: $value === null ? null : new Amount($value, self::string($data['currency'] ?? null) ?? 'NOK'),
            environment: self::string($data['environment'] ?? null),
            merchantName: self::string($data['merchantName'] ?? null),
            merchantOrgNr: self::string($data['merchantOrgNr'] ?? null),
            returnUrl: self::string($data['returnUrl'] ?? null),
            invoiceUrl: self::string($data['invoice_url'] ?? null),
            expiresAt: self::date($data['expiresAt'] ?? null),
            expired: self::bool($data['expired'] ?? null) ?? false,
            raw: $data,
        );
    }
}
