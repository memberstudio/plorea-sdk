<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Tests\Unit;

use InvalidArgumentException;
use MemberFlow\Plorea\Data\BillingInterval;
use MemberFlow\Plorea\Enums\IntervalUnit;
use PHPUnit\Framework\TestCase;

class BillingIntervalTest extends TestCase
{
    public function test_named_constructors(): void
    {
        $this->assertSame(IntervalUnit::Day, BillingInterval::daily()->unit);
        $this->assertSame(IntervalUnit::Week, BillingInterval::weekly()->unit);
        $this->assertSame(IntervalUnit::Month, BillingInterval::monthly()->unit);
        $this->assertSame(IntervalUnit::Year, BillingInterval::yearly()->unit);
        $this->assertSame(3, BillingInterval::monthly(3)->count);
    }

    public function test_it_serializes(): void
    {
        $this->assertSame(['unit' => 'month', 'count' => 1], BillingInterval::monthly()->toArray());
    }

    public function test_it_parses_from_arrays(): void
    {
        $this->assertNull(BillingInterval::fromArray(null));

        $interval = BillingInterval::fromArray(['unit' => 'week', 'count' => 2]);

        $this->assertSame(IntervalUnit::Week, $interval?->unit);
        $this->assertSame(2, $interval->count);
    }

    public function test_it_rejects_invalid_counts(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BillingInterval(IntervalUnit::Month, 0);
    }
}
