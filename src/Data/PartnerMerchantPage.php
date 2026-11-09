<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Data;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use MemberFlow\Plorea\Data\Concerns\ParsesResponseData;

/**
 * One page of a partner's merchants, newest first.
 *
 * Read from live on 2026-11-09. `kycDeferred` counts the merchants on this
 * page whose KYC was not read in time; when it is above zero, ask again.
 */
final readonly class PartnerMerchantPage
{
    use ParsesResponseData;

    /**
     * @param  Collection<int, PartnerMerchant>  $items
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public ?string $tenantId,
        public int $count,
        public ?string $nextCursor,
        public int $kycDeferred,
        public ?CarbonImmutable $checkedAt,
        public Collection $items,
        public array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];

        return new self(
            tenantId: self::string($data['tenantId'] ?? null),
            count: self::int($data['count'] ?? null) ?? count($items),
            nextCursor: self::string($data['nextCursor'] ?? null),
            kycDeferred: self::int($data['kycDeferred'] ?? null) ?? 0,
            checkedAt: self::date($data['checkedAt'] ?? null),
            items: new Collection($items)
                ->filter(fn (mixed $item): bool => is_array($item))
                ->map(fn (array $item): PartnerMerchant => PartnerMerchant::fromArray($item))
                ->values(),
            raw: $data,
        );
    }

    /**
     * Whether there is a page after this one.
     */
    public function hasMore(): bool
    {
        return $this->nextCursor !== null && $this->nextCursor !== '';
    }
}
