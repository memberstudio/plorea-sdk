<?php

declare(strict_types=1);

namespace MemberFlow\Plorea;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;
use LogicException;
use MemberFlow\Plorea\Contracts\Client;
use MemberFlow\Plorea\Resources\PayByLinkResource;
use MemberFlow\Plorea\Resources\PaymentMethodResource;
use MemberFlow\Plorea\Resources\PaymentResource;
use MemberFlow\Plorea\Resources\SubscriptionResource;
use MemberFlow\Plorea\Testing\FakeClient;
use MemberFlow\Plorea\Testing\RecordedRequest;

class PloreaManager
{
    protected ?FakeClient $fake = null;

    public function __construct(protected Container $container) {}

    /**
     * Payment links, statuses, refunds and cancellations.
     */
    public function payments(): PaymentResource
    {
        return new PaymentResource($this->client(), $this->config());
    }

    /**
     * Stored payment method setup and lookup.
     */
    public function paymentMethods(): PaymentMethodResource
    {
        return new PaymentMethodResource($this->client(), $this->config());
    }

    /**
     * Subscription lifecycle operations.
     */
    public function subscriptions(): SubscriptionResource
    {
        return new SubscriptionResource($this->client(), $this->config());
    }

    /**
     * Unauthenticated endpoints used internally by pay.plorea.no.
     */
    public function payByLink(): PayByLinkResource
    {
        return new PayByLinkResource($this->client(), $this->config());
    }

    /**
     * The client used for API communication.
     */
    public function client(): Client
    {
        return $this->fake ?? $this->container->make(Client::class);
    }

    /**
     * Replace the API client with a fake for testing.
     *
     * @param  array<string, array<string, mixed>|callable|\Throwable>  $stubs
     */
    public function fake(array $stubs = []): FakeClient
    {
        return $this->fake = new FakeClient($stubs);
    }

    /**
     * Whether the client has been replaced with a fake.
     */
    public function isFaked(): bool
    {
        return $this->fake instanceof FakeClient;
    }

    /**
     * All requests recorded by the fake client.
     *
     * @return Collection<int, RecordedRequest>
     */
    public function recorded(): Collection
    {
        return $this->fakeOrFail()->recorded();
    }

    /**
     * Assert that a request matching the given path pattern or truth test was sent.
     */
    public function assertSent(string|Closure $matcher): void
    {
        $this->fakeOrFail()->assertSent($matcher);
    }

    /**
     * Assert that no request matching the given path pattern or truth test was sent.
     */
    public function assertNotSent(string|Closure $matcher): void
    {
        $this->fakeOrFail()->assertNotSent($matcher);
    }

    /**
     * Assert that no requests were sent at all.
     */
    public function assertNothingSent(): void
    {
        $this->fakeOrFail()->assertNothingSent();
    }

    /**
     * Assert the total number of requests that were sent.
     */
    public function assertSentCount(int $count): void
    {
        $this->fakeOrFail()->assertSentCount($count);
    }

    protected function fakeOrFail(): FakeClient
    {
        return $this->fake ?? throw new LogicException(
            'The Plorea client has not been faked. Call Plorea::fake() first.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function config(): array
    {
        /** @var array<string, mixed> */
        return $this->container->make(Repository::class)->get('plorea', []);
    }
}
