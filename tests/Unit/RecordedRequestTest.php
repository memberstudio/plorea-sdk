<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Tests\Unit;

use MemberFlow\Plorea\Testing\RecordedRequest;
use PHPUnit\Framework\TestCase;

class RecordedRequestTest extends TestCase
{
    public function test_it_matches_path_patterns(): void
    {
        $request = new RecordedRequest('post', 'payments/link', ['reference' => 'ref-1']);

        $this->assertTrue($request->matches('payments/link'));
        $this->assertTrue($request->matches('payments/*'));
        $this->assertTrue($request->matches('POST payments/link'));
        $this->assertTrue($request->matches('post payments/*'));
        $this->assertFalse($request->matches('GET payments/link'));
        $this->assertFalse($request->matches('subscriptions/*'));
    }

    public function test_it_reads_input_with_dot_notation(): void
    {
        $request = new RecordedRequest('post', 'subscriptions', [
            'amount' => ['value' => 19900, 'currency' => 'NOK'],
        ]);

        $this->assertSame(19900, $request->input('amount.value'));
        $this->assertSame('fallback', $request->input('missing', 'fallback'));
    }
}
