<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Tests\Unit;

use InvalidArgumentException;
use MemberFlow\Plorea\Http\IdempotencyKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IdempotencyKeyTest extends TestCase
{
    public function test_no_key_sends_no_header(): void
    {
        $this->assertSame([], IdempotencyKey::headers(null));
    }

    public function test_a_key_of_the_maximum_length_is_sent(): void
    {
        $key = str_repeat('k', 128);

        $this->assertSame(['Idempotency-Key' => $key], IdempotencyKey::headers($key));
    }

    #[DataProvider('invalidKeys')]
    public function test_an_invalid_key_is_refused_before_sending(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);

        IdempotencyKey::headers($key);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidKeys(): array
    {
        return [
            'empty' => [''],
            'whitespace' => ['   '],
            'too long' => [str_repeat('k', 129)],
        ];
    }
}
