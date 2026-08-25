<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Tests\Unit;

use MemberFlow\Plorea\Data\Amount;
use PHPUnit\Framework\TestCase;

class AmountTest extends TestCase
{
    public function test_it_holds_minor_units(): void
    {
        $amount = Amount::nok(450000);

        $this->assertSame(450000, $amount->value);
        $this->assertSame('NOK', $amount->currency);
        $this->assertSame(4500.0, $amount->inMajorUnits());
        $this->assertSame(['value' => 450000, 'currency' => 'NOK'], $amount->toArray());
    }

    public function test_it_parses_from_arrays(): void
    {
        $this->assertNull(Amount::fromArray(null));
        $this->assertNull(Amount::fromArray(['currency' => 'NOK']));

        $amount = Amount::fromArray(['value' => 19900, 'currency' => 'EUR']);

        $this->assertSame(19900, $amount?->value);
        $this->assertSame('EUR', $amount->currency);

        $this->assertSame('NOK', Amount::fromArray(['value' => 100])?->currency);
    }
}
