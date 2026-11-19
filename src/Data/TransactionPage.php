<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Data;

use Illuminate\Support\Collection;
use MemberFlow\Plorea\Data\Concerns\ParsesResponseData;

/**
 * One page of the tenant's transaction list.
 *
 * Verified in test 2026-11-09: `count` is the number of rows on this page,
 * and `nextCursor` is null on the last page. A page can hold fewer rows than
 * the limit and still have a next page.
 */
final readonly class TransactionPage
{
    use ParsesResponseData;

    /**
     * @param  Collection<int, Transaction>  $items
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public ?string $tenantId,
        public int $count,
        public ?string $nextCursor,
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
            items: new Collection($items)
                ->filter(fn (mixed $item): bool => is_array($item))
                ->map(fn (array $item): Transaction => Transaction::fromArray($item))
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
