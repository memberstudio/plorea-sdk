<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Data;

use MemberFlow\Plorea\Data\Concerns\ParsesResponseData;

/**
 * One onboarding step of a partner merchant, for example `kyc` or `store`.
 *
 * Statuses read from live on 2026-11-09 (21 merchants):
 *
 * - merchant, legalEntity: `existed`, `missing`
 * - kyc: `approved`, `action_required`, `missing` (spec adds `pending`, `deferred`, `unknown`)
 * - balanceAccount: `ready`, `missing`
 * - store: `existed`, `missing`
 * - cardMethods: `ready`, `no_store`, `missing` (spec adds `pending`, `blocked_no_business_line`, `partial`, `deferred`, `unknown`)
 * - partnerRow: `written`
 *
 * The other keys differ per step; read them from `raw`.
 */
final readonly class PartnerMerchantStep
{
    use ParsesResponseData;

    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $name,
        public ?string $status,
        public array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(string $name, array $data): self
    {
        return new self(
            name: $name,
            status: self::string($data['status'] ?? null),
            raw: $data,
        );
    }

    /**
     * Whether the step status matches the given value.
     */
    public function is(string $status): bool
    {
        return $this->status !== null && strcasecmp($this->status, $status) === 0;
    }
}
