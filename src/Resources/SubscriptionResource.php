<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Resources;

use Illuminate\Support\Collection;
use MemberFlow\Plorea\Data\Amount;
use MemberFlow\Plorea\Data\BillingInterval;
use MemberFlow\Plorea\Data\Subscription;
use MemberFlow\Plorea\Data\SubscriptionCancellation;
use MemberFlow\Plorea\Data\SubscriptionCharge;
use MemberFlow\Plorea\Enums\RecurringType;
use MemberFlow\Plorea\Exceptions\ChargeFailedException;
use MemberFlow\Plorea\Pending\PendingSubscription;
use MemberFlow\Plorea\Pending\PendingSubscriptionUpdate;

class SubscriptionResource extends Resource
{
    /**
     * Start building a subscription linked to an active payment method.
     *
     * ```php
     * $subscription = Plorea::subscriptions()
     *     ->create('pm_63cd...', Amount::nok(19900), BillingInterval::monthly())
     *     ->externalId('ws_acme_456')
     *     ->title('Done CRM Pro')
     *     ->vat(rate: 0.25, amount: 3980)
     *     ->save();
     * ```
     */
    public function create(
        string $paymentMethodId,
        Amount $amount,
        BillingInterval $interval,
        RecurringType $recurringType = RecurringType::Subscription,
    ): PendingSubscription {
        return new PendingSubscription(
            $this->client,
            $this->tenantId(),
            $paymentMethodId,
            $amount,
            $interval,
            $recurringType,
        );
    }

    /**
     * The current stored state for a subscription.
     */
    public function find(string $subscriptionId): Subscription
    {
        return Subscription::fromArray(
            $this->client->get('subscriptions/'.rawurlencode($subscriptionId)),
        );
    }

    /**
     * Start building a partial update for a subscription.
     *
     * ```php
     * Plorea::subscriptions()->update('sub_774c...')
     *     ->quantity(10)
     *     ->amount(Amount::nok(39900))
     *     ->save();
     * ```
     */
    public function update(string $subscriptionId): PendingSubscriptionUpdate
    {
        return new PendingSubscriptionUpdate($this->client, $subscriptionId);
    }

    /**
     * All subscriptions matching an external reference (e.g. a workspace ID).
     *
     * @return Collection<int, Subscription>
     */
    public function forExternalId(string $externalId, ?string $tenantId = null, ?string $status = null): Collection
    {
        $response = $this->client->get('subscriptions', $this->withoutNulls([
            'externalId' => $externalId,
            'tenantId' => $tenantId,
            'status' => $status,
        ]));

        $items = is_array($response['items'] ?? null) ? $response['items'] : [];

        return new Collection($items)
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(fn (array $item): Subscription => Subscription::fromArray($item))
            ->values();
    }

    /**
     * Create a manual charge using the subscription's stored payment method.
     *
     * VAT falls back to the subscription's values when omitted.
     *
     * @throws ChargeFailedException When the charge is declined.
     */
    public function charge(
        string $subscriptionId,
        ?Amount $amount = null,
        ?string $reason = null,
        ?float $vatRate = null,
        ?int $vatAmount = null,
    ): SubscriptionCharge {
        return SubscriptionCharge::fromArray($this->client->post(
            'subscriptions/'.rawurlencode($subscriptionId).'/charge',
            $this->withoutNulls([
                'reason' => $reason,
                'amount' => $amount?->toArray(),
                'vatRate' => $vatRate,
                'vatAmount' => $vatAmount,
            ]),
        ));
    }

    /**
     * All recorded charges for a subscription, newest first.
     *
     * @return Collection<int, SubscriptionCharge>
     */
    public function charges(string $subscriptionId): Collection
    {
        $response = $this->client->get('subscriptions/'.rawurlencode($subscriptionId).'/charges');

        $items = is_array($response['items'] ?? null) ? $response['items'] : [];

        return new Collection($items)
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(fn (array $item): SubscriptionCharge => SubscriptionCharge::fromArray($item))
            ->values();
    }

    /**
     * Cancel a subscription and clear future scheduling.
     */
    public function cancel(string $subscriptionId, ?string $reason = null): SubscriptionCancellation
    {
        return SubscriptionCancellation::fromArray($this->client->post(
            'subscriptions/'.rawurlencode($subscriptionId).'/cancel',
            $this->withoutNulls(['reason' => $reason]),
        ));
    }

    /**
     * Reactivate a canceled subscription. A new charge is scheduled immediately.
     */
    public function reactivate(string $subscriptionId): Subscription
    {
        return Subscription::fromArray($this->client->post(
            'subscriptions/'.rawurlencode($subscriptionId).'/reactivate',
        ));
    }
}
