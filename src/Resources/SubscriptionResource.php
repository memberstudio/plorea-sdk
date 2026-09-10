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
     * Subscriptions matching an external reference (e.g. a workspace ID).
     *
     * The response is {externalId, count, items} with no cursor, page or
     * hasMore key, and every capture so far has held a single item, so
     * whether "count" is the total or just what was returned is unverified
     * and the endpoint has never been seen to paginate. The SDK sends no
     * paging parameters, because guessing their names would be worse than
     * not sending them. If you hold more than a handful of subscriptions
     * against one external id, verify the count against your own records
     * before trusting this to be exhaustive.
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
     * Subscriptions for an external reference that look like they need a
     * human — the polling half of dunning.
     *
     * A failed scheduler charge emits no webhook (confirmed by Plorea
     * 2026-09-09), so the only way to notice one is to ask. Run this on a
     * schedule for each of your billed entities.
     *
     * A subscription is returned when it reports the payment_failed status,
     * or when its nextChargeAt is more than $graceMinutes in the past. The
     * second test is the one that carries the weight: payment_failed is
     * modelled from Plorea's documentation and has never been observed,
     * because no test card can store successfully and then decline, while an
     * overdue nextChargeAt is derived from fields captured on the wire. If
     * Plorea's failure shape turns out to differ from the documentation, the
     * overdue check still fires.
     *
     * This tells you which subscriptions to look at, not what went wrong.
     * Read charges() for that — a scheduler charge cannot be resolved
     * through payments()->status().
     *
     * ```php
     * foreach (Plorea::subscriptions()->needingAttention($workspace->externalId) as $subscription) {
     *     $latest = Plorea::subscriptions()->charges($subscription->id)->first();
     *
     *     if ($latest?->isAuthorised() !== true) {
     *         // Prompt for a new card. Do not branch on failureReason — it
     *         // is null even for a genuine refusal.
     *     }
     * }
     * ```
     *
     * @return Collection<int, Subscription>
     */
    public function needingAttention(
        string $externalId,
        ?string $tenantId = null,
        int $graceMinutes = 60,
    ): Collection {
        return $this->forExternalId($externalId, $tenantId)
            ->filter(fn (Subscription $subscription): bool => $subscription->hasPaymentFailure()
                || $subscription->isOverdue($graceMinutes))
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
     * Recorded charges for a subscription, newest first.
     *
     * The response is {subscriptionId, items} — no count and no paging keys
     * of any kind, so a truncated history would be indistinguishable from a
     * complete one. The longest capture holds two items. Treat a long-lived
     * monthly subscription's history as unverified territory.
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
