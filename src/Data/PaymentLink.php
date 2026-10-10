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
     * Plorea's own pay page also reads `orgNr`, `invoiceUrl` and
     * `status: "expired"`, so accept them too. A missed expiry is the costly
     * one: the payer would be sent to a dead link. An empty value counts as
     * missing, so it does not hide a filled alias.
     *
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
            merchantOrgNr: self::filled($data['merchantOrgNr'] ?? null) ?? self::filled($data['orgNr'] ?? null),
            returnUrl: self::string($data['returnUrl'] ?? null),
            invoiceUrl: self::filled($data['invoice_url'] ?? null) ?? self::filled($data['invoiceUrl'] ?? null),
            expiresAt: self::date($data['expiresAt'] ?? null),
            expired: self::bool($data['expired'] ?? null) === true || self::string($data['status'] ?? null) === 'expired',
            raw: $data,
        );
    }

    private static function filled(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
