<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Pending;

use MemberFlow\Plorea\Contracts\Client;
use MemberFlow\Plorea\Data\Amount;
use MemberFlow\Plorea\Data\Subscription;
use MemberFlow\Plorea\Exceptions\PloreaException;

/**
 * Fluently builds a partial subscription update. Only fields that are set
 * are sent — omitted fields are left unchanged.
 */
class PendingSubscriptionUpdate
{
    /** @var array<string, mixed> */
    protected array $changes = [];

    public function __construct(
        protected readonly Client $client,
        protected readonly string $subscriptionId,
    ) {}

    /**
     * An opaque reference linking the subscription to an entity in your system.
     */
    public function externalId(string $externalId): static
    {
        $this->changes['externalId'] = $externalId;

        return $this;
    }

    public function title(string $title): static
    {
        $this->changes['title'] = $title;

        return $this;
    }

    public function description(string $description): static
    {
        $this->changes['description'] = $description;

        return $this;
    }

    public function amount(Amount $amount): static
    {
        $this->changes['amount'] = $amount->toArray();

        return $this;
    }

    public function quantity(int $quantity): static
    {
        $this->changes['quantity'] = $quantity;

        return $this;
    }

    /**
     * VAT details, stored as-is by Plorea.
     */
    public function vat(float $rate, int $amount): static
    {
        $this->changes['vatRate'] = $rate;
        $this->changes['vatAmount'] = $amount;

        return $this;
    }

    /**
     * Replace the payment method on the subscription. After replacing it on a
     * payment_failed subscription, call reactivate() to resume billing.
     */
    public function paymentMethod(string $paymentMethodId): static
    {
        $this->changes['paymentMethodId'] = $paymentMethodId;

        return $this;
    }

    /**
     * Apply the update.
     */
    public function save(): Subscription
    {
        if ($this->changes === []) {
            throw new PloreaException('A subscription update requires at least one field to change.');
        }

        return Subscription::fromArray($this->client->patch(
            'subscriptions/'.rawurlencode($this->subscriptionId),
            $this->changes,
        ));
    }
}
