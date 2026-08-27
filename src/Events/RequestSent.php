<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Events;

/**
 * Dispatched right before the SDK sends a request to the Plorea API.
 *
 * Carries only the method, relative URI, and request payload — never the
 * API key or any headers.
 */
class RequestSent
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $method,
        public string $uri,
        public array $payload,
    ) {}
}
