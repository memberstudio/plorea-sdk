<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Testing;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * A request captured by the fake client.
 */
final readonly class RecordedRequest
{
    /**
     * @param  array<string, mixed>  $data  The request payload (POST/PATCH) or query string (GET).
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $data = [],
    ) {}

    /**
     * Whether the request matches the given path pattern. Patterns may use
     * wildcards and optionally lead with a method: "POST payments/link".
     */
    public function matches(string $pattern): bool
    {
        if (Str::is($pattern, $this->path)) {
            return true;
        }

        if (! str_contains($pattern, ' ')) {
            return false;
        }

        [$method, $path] = explode(' ', $pattern, 2);

        return strcasecmp($method, $this->method) === 0 && Str::is($path, $this->path);
    }

    /**
     * A value from the request payload using dot notation.
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->data, $key, $default);
    }
}
