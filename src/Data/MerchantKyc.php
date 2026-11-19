<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Data;

use MemberFlow\Plorea\Data\Concerns\ParsesResponseData;

/**
 * The provider's current receivePayments capability, not stored kycStatus.
 * Deferred means the list did not attempt the lookup; unknown means no answer.
 */
final readonly class MerchantKyc
{
    use ParsesResponseData;

    /**
     * @param  list<MerchantKycProblem>  $problems
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public ?string $status,
        public ?string $legalEntityId,
        public ?string $verificationStatus,
        public array $problems,
        public array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $problems = is_array($data['problems'] ?? null) ? $data['problems'] : [];

        return new self(
            status: self::string($data['status'] ?? null),
            legalEntityId: self::string($data['legalEntityId'] ?? null),
            verificationStatus: self::string($data['verificationStatus'] ?? null),
            problems: array_values(array_map(
                MerchantKycProblem::fromArray(...),
                array_filter($problems, is_array(...)),
            )),
            raw: $data,
        );
    }

    public function is(string $status): bool
    {
        return $this->status !== null && strcasecmp($this->status, $status) === 0;
    }

    public function isApproved(): bool
    {
        return $this->is('approved');
    }
}
