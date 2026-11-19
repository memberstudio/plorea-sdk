<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Data;

use MemberFlow\Plorea\Data\Concerns\ParsesResponseData;

/**
 * Branch on code or type, not the provider's operator-facing message.
 * pendingStatus (1_77) is ordinary processing, not action required.
 */
final readonly class MerchantKycProblem
{
    use ParsesResponseData;

    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public ?string $entityType,
        public ?string $entityId,
        public ?string $code,
        public ?string $type,
        public ?string $message,
        public array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            entityType: self::string($data['entityType'] ?? null),
            entityId: self::string($data['entityId'] ?? null),
            code: self::string($data['code'] ?? null),
            type: self::string($data['type'] ?? null),
            message: self::string($data['message'] ?? null),
            raw: $data,
        );
    }
}
