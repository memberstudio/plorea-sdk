<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Testing;

use Closure;
use Illuminate\Support\Collection;
use MemberFlow\Plorea\Contracts\Client;
use PHPUnit\Framework\Assert as PHPUnit;
use Throwable;

/**
 * An in-memory replacement for the Plorea API client.
 *
 * Requests are recorded instead of sent. Responses come from the registered
 * stubs, falling back to sensible defaults for every endpoint so most tests
 * need no stubbing at all.
 */
class FakeClient implements Client
{
    /** @var list<RecordedRequest> */
    protected array $requests = [];

    /**
     * @param  array<string, array<string, mixed>|callable|Throwable>  $stubs  Path patterns
     *                                                                         (optionally prefixed with a method, e.g. "POST payments/link") mapped to a
     *                                                                         response array, a callable receiving the RecordedRequest, or a Throwable.
     */
    public function __construct(protected array $stubs = []) {}

    /**
     * Register additional response stubs.
     *
     * @param  array<string, array<string, mixed>|callable|Throwable>  $stubs
     */
    public function stub(array $stubs): static
    {
        $this->stubs = [...$this->stubs, ...$stubs];

        return $this;
    }

    public function get(string $uri, array $query = []): array
    {
        return $this->record(new RecordedRequest('get', $uri, $query));
    }

    public function post(string $uri, array $payload = []): array
    {
        return $this->record(new RecordedRequest('post', $uri, $payload));
    }

    public function patch(string $uri, array $payload = []): array
    {
        return $this->record(new RecordedRequest('patch', $uri, $payload));
    }

    /**
     * @return array<string, mixed>
     */
    protected function record(RecordedRequest $request): array
    {
        $this->requests[] = $request;

        foreach ($this->stubs as $pattern => $response) {
            if (! $request->matches($pattern)) {
                continue;
            }

            if ($response instanceof Throwable) {
                throw $response;
            }

            if ($response instanceof Closure || is_callable($response)) {
                $response = $response($request);
            }

            if ($response instanceof Throwable) {
                throw $response;
            }

            /** @var array<string, mixed> $response */
            return $response;
        }

        return DefaultFixtures::for($request, $this->requests);
    }

    /**
     * @return Collection<int, RecordedRequest>
     */
    public function recorded(): Collection
    {
        return new Collection($this->requests);
    }

    public function assertSent(string|Closure $matcher): void
    {
        PHPUnit::assertTrue(
            $this->matching($matcher)->isNotEmpty(),
            is_string($matcher)
                ? "An expected request matching [{$matcher}] was not sent to Plorea."
                : 'An expected request was not sent to Plorea.',
        );
    }

    public function assertNotSent(string|Closure $matcher): void
    {
        PHPUnit::assertTrue(
            $this->matching($matcher)->isEmpty(),
            is_string($matcher)
                ? "An unexpected request matching [{$matcher}] was sent to Plorea."
                : 'An unexpected request was sent to Plorea.',
        );
    }

    public function assertNothingSent(): void
    {
        PHPUnit::assertCount(0, $this->requests, 'Requests were unexpectedly sent to Plorea.');
    }

    public function assertSentCount(int $count): void
    {
        PHPUnit::assertCount($count, $this->requests);
    }

    /**
     * @return Collection<int, RecordedRequest>
     */
    protected function matching(string|Closure $matcher): Collection
    {
        return $this->recorded()->filter(
            fn (RecordedRequest $request): bool => is_string($matcher)
                ? $request->matches($matcher)
                : (bool) $matcher($request),
        );
    }
}
