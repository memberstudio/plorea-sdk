<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Tests\Unit;

use MemberFlow\Plorea\Data\PaymentLink;
use PHPUnit\Framework\TestCase;

class ParsesResponseDataTest extends TestCase
{
    public function test_a_malformed_date_becomes_null_instead_of_throwing(): void
    {
        $link = PaymentLink::fromArray([
            'id' => 'pl_1',
            'expiresAt' => 'not-a-date',
        ]);

        $this->assertNull($link->expiresAt);
        $this->assertFalse($link->expired);
    }

    public function test_a_valid_date_still_parses(): void
    {
        $link = PaymentLink::fromArray([
            'id' => 'pl_1',
            'expiresAt' => '2099-12-31T12:00:00Z',
        ]);

        $this->assertSame('2099-12-31 12:00:00', $link->expiresAt?->format('Y-m-d H:i:s'));
    }
}
