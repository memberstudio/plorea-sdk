<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Data;

use Carbon\CarbonImmutable;
use MemberFlow\Plorea\Data\Concerns\ParsesResponseData;

/**
 * The result of creating a payment link. Redirect the customer to `url`.
 */
final readonly class PaymentLinkCreated
{
    use ParsesResponseData;

    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public ?string $status,
        public ?string $environment,
        public string $url,
        public string $id,
        public ?string $reference,
        public ?string $tenantId,
        public ?string $doneId,
        public ?string $merchantAccount,
        public ?string $store,
        public ?string $balanceAccountId,
        public ?bool $splitsEnabled,
        public ?string $provider,
        public ?CarbonImmutable $expiresAt,
        public ?bool $partnerSplitsApplied = null,
        public array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            status: self::string($data['status'] ?? null),
            environment: self::string($data['environment'] ?? null),
            url: self::string($data['paymentLinkUrl'] ?? null) ?? '',
            id: self::string($data['paymentLinkId'] ?? null) ?? '',
            reference: self::string($data['reference'] ?? null),
            tenantId: self::string($data['tenantId'] ?? null),
            doneId: self::string($data['doneId'] ?? null),
            merchantAccount: self::string($data['merchantAccount'] ?? null),
            store: self::string($data['store'] ?? null),
            balanceAccountId: self::string($data['balanceAccountId'] ?? null),
            splitsEnabled: self::bool($data['splitsEnabled'] ?? null),
            provider: self::string($data['provider'] ?? null),
            expiresAt: self::date($data['expiresAt'] ?? null),
            partnerSplitsApplied: self::bool($data['partnerSplitsApplied'] ?? null),
            raw: $data,
        );
    }
}
