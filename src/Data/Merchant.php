<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Data;

use Carbon\CarbonImmutable;
use MemberFlow\Plorea\Data\Concerns\ParsesResponseData;

/**
 * A merchant from a list, a status read or an onboarding response.
 * Fields absent from that endpoint remain null. KYC comes only from steps.kyc.
 */
final readonly class Merchant
{
    use ParsesResponseData;

    /**
     * @param  array<string, mixed>  $steps  All onboarding steps, including partial failures.
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $merchantOrgNr,
        public ?string $tenantId,
        public ?string $name,
        public ?string $email,
        public ?string $kycUrl,
        public ?string $storeId,
        public ?bool $created,
        public ?CarbonImmutable $checkedAt,
        public MerchantKyc $kyc,
        public array $steps,
        public array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $steps = self::metadata($data['steps'] ?? null);

        return new self(
            merchantOrgNr: self::string($data['merchantOrgNr'] ?? null) ?? '',
            tenantId: self::string($data['tenantId'] ?? null),
            name: self::string($data['name'] ?? null),
            email: self::string($data['email'] ?? null),
            kycUrl: self::string($data['kycUrl'] ?? null),
            storeId: self::string($data['storeId'] ?? null),
            created: self::bool($data['created'] ?? null),
            checkedAt: self::date($data['checkedAt'] ?? null),
            kyc: MerchantKyc::fromArray(self::metadata($steps['kyc'] ?? null)),
            steps: $steps,
            raw: $data,
        );
    }
}
