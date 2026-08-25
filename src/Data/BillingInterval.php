<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Data;

use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;
use MemberFlow\Plorea\Enums\IntervalUnit;

/**
 * How often a subscription is billed (e.g. every 1 month).
 *
 * @implements Arrayable<string, int|string>
 */
final readonly class BillingInterval implements Arrayable
{
    public function __construct(
        public IntervalUnit $unit,
        public int $count = 1,
    ) {
        if ($count < 1) {
            throw new InvalidArgumentException('The billing interval count must be at least 1.');
        }
    }

    public static function daily(int $count = 1): self
    {
        return new self(IntervalUnit::Day, $count);
    }

    public static function weekly(int $count = 1): self
    {
        return new self(IntervalUnit::Week, $count);
    }

    public static function monthly(int $count = 1): self
    {
        return new self(IntervalUnit::Month, $count);
    }

    public static function yearly(int $count = 1): self
    {
        return new self(IntervalUnit::Year, $count);
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): ?self
    {
        if ($data === null || ! is_string($data['unit'] ?? null)) {
            return null;
        }

        $unit = IntervalUnit::tryFrom($data['unit']);

        if ($unit === null) {
            return null;
        }

        return new self(
            unit: $unit,
            count: is_numeric($data['count'] ?? null) ? max(1, (int) $data['count']) : 1,
        );
    }

    /**
     * @return array{unit: string, count: int}
     */
    public function toArray(): array
    {
        return [
            'unit' => $this->unit->value,
            'count' => $this->count,
        ];
    }
}
