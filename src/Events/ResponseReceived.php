<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Events;

/**
 * Dispatched for every HTTP response the Plorea API returns — including
 * error responses, right before the corresponding exception is thrown.
 *
 * Not dispatched when the API could not be reached at all
 * (ConnectionException). Carries only the method, relative URI, payload,
 * and response data — never the API key or any headers.
 */
class ResponseReceived
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $response  The decoded JSON body, or null when the body is not a JSON object.
     */
    public function __construct(
        public string $method,
        public string $uri,
        public array $payload,
        public int $status,
        public ?array $response,
        public float $durationMs,
    ) {}
}
