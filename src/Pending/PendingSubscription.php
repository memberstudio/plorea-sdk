<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Pending;

use DateTimeInterface;
use MemberFlow\Plorea\Concerns\FiltersNullValues;
use MemberFlow\Plorea\Contracts\Client;
use MemberFlow\Plorea\Data\Amount;
use MemberFlow\Plorea\Data\BillingInterval;
use MemberFlow\Plorea\Data\RetryPolicy;
use MemberFlow\Plorea\Data\Subscription;
use MemberFlow\Plorea\Enums\RecurringType;

/**
 * Fluently configures and creates a subscription.
 */
class PendingSubscription
{
    use FiltersNullValues;

    protected ?string $customerId = null;

    protected ?string $doneId = null;

    protected ?DateTimeInterface $trialEndsAt = null;

    protected ?RetryPolicy $retryPolicy = null;

    protected ?string $externalId = null;

    protected ?string $title = null;

    protected ?string $description = null;

    protected ?int $quantity = null;

    protected ?float $vatRate = null;

    protected ?int $vatAmount = null;

    /** @var array<string, mixed> */
    protected array $metadata = [];

    public function __construct(
        protected readonly Client $client,
        protected string $tenantId,
        protected readonly string $paymentMethodId,
        protected readonly Amount $amount,
        protected readonly BillingInterval $interval,
        protected readonly RecurringType $recurringType,
    ) {}

    /**
     * Override the tenant from the package configuration.
     */
    public function tenant(string $tenantId): static
    {
        $this->tenantId = $tenantId;

        return $this;
    }

    /**
     * Your own customer identifier.
     */
    public function customerId(string $customerId): static
    {
        $this->customerId = $customerId;

        return $this;
    }

    /**
     * The universal Done user identifier.
     */
    public function doneId(string $doneId): static
    {
        $this->doneId = $doneId;

        return $this;
    }

    /**
     * Delay the first charge until the trial ends.
     */
    public function trialUntil(DateTimeInterface $trialEndsAt): static
    {
        $this->trialEndsAt = $trialEndsAt;

        return $this;
    }

    /**
     * How failed charges are retried.
     */
    public function retryPolicy(RetryPolicy|int $policy, int $retryIntervalDays = 1): static
    {
        $this->retryPolicy = $policy instanceof RetryPolicy
            ? $policy
            : new RetryPolicy($policy, $retryIntervalDays);

        return $this;
    }

    /**
     * An opaque reference linking the subscription to an entity in your
     * system, such as a workspace. Can be set later via update().
     */
    public function externalId(string $externalId): static
    {
        $this->externalId = $externalId;

        return $this;
    }

    /**
     * A human-readable label for the subscription.
     */
    public function title(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function description(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    /**
     * The number of seats or units.
     */
    public function quantity(int $quantity): static
    {
        $this->quantity = $quantity;

        return $this;
    }

    /**
     * VAT details, stored as-is by Plorea. The rate is a decimal between 0
     * and 1 (0.25 for 25%); the amount is the VAT portion in minor units.
     */
    public function vat(float $rate, int $amount): static
    {
        $this->vatRate = $rate;
        $this->vatAmount = $amount;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function metadata(array $metadata): static
    {
        $this->metadata = $metadata;

        return $this;
    }

    /**
     * Create the subscription.
     */
    public function save(): Subscription
    {
        return Subscription::fromArray(
            $this->client->post('subscriptions', $this->toPayload()),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return $this->withoutNulls([
            'tenantId' => $this->tenantId,
            'customerId' => $this->customerId,
            'doneId' => $this->doneId,
            'paymentMethodId' => $this->paymentMethodId,
            'recurringType' => $this->recurringType->value,
            'amount' => $this->amount->toArray(),
            'interval' => $this->interval->toArray(),
            'trialEndsAt' => $this->trialEndsAt?->format(DateTimeInterface::ATOM),
            'retryPolicy' => $this->retryPolicy?->toArray(),
            'externalId' => $this->externalId,
            'title' => $this->title,
            'description' => $this->description,
            'quantity' => $this->quantity,
            'vatRate' => $this->vatRate,
            'vatAmount' => $this->vatAmount,
            'metadata' => $this->metadata === [] ? null : $this->metadata,
        ]);
    }
}
