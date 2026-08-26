<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Concerns;

trait FiltersNullValues
{
    /**
     * Drop null values from a request payload or query, keeping only the
     * fields the caller actually set.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function withoutNulls(array $payload): array
    {
        return array_filter($payload, fn (mixed $value): bool => $value !== null);
    }
}
