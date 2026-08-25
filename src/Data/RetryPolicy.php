<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Data;

use Illuminate\Contracts\Support\Arrayable;

/**
 * How failed subscription charges are retried.
 *
 * @implements Arrayable<string, int>
 */
final readonly class RetryPolicy implements Arrayable
{
    public function __construct(
        public int $maxRetries = 3,
        public int $retryIntervalDays = 1,
    ) {}

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): ?self
    {
        if ($data === null || $data === []) {
            return null;
        }

        return new self(
            maxRetries: is_numeric($data['maxRetries'] ?? null) ? (int) $data['maxRetries'] : 3,
            retryIntervalDays: is_numeric($data['retryIntervalDays'] ?? null) ? (int) $data['retryIntervalDays'] : 1,
        );
    }

    /**
     * @return array{maxRetries: int, retryIntervalDays: int}
     */
    public function toArray(): array
    {
        return [
            'maxRetries' => $this->maxRetries,
            'retryIntervalDays' => $this->retryIntervalDays,
        ];
    }
}
