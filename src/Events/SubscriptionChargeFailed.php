<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Events;

/**
 * Dispatched for a `subscription.charge_failed` webhook — Plorea's
 * scheduler tried to charge the stored card and failed. The subscription
 * normally moves to past_due, and Plorea retries on its own schedule.
 *
 * Captured 2026-10-01 and 2026-10-06 (test environment, failureReason
 * "PaymentDetail not found", retryCount 1). Unlike the success event, the
 * payload carries no pspReference and no nextChargeAt.
 *
 * Treat it as a ping: fetch the subscription with
 * Plorea::subscriptions()->find($subscriptionId) and read the history from
 * charges(). Keep polling needingAttention() as well — a failed delivery
 * is never redelivered, and a stalled cycle emits nothing at all.
 *
 * $failureReason is Plorea's free-text reason; show it, never branch on
 * it. $retryCount is how many attempts Plorea reports at the time of the
 * delivery.
 *
 * $eventId is Plorea's identifier for the delivery and is what you should
 * deduplicate on. Both it and $type come from the request body rather than
 * the headers, because the signature covers the body only.
 */
class SubscriptionChargeFailed
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $subscriptionId,
        public ?string $chargeId,
        public ?string $reference,
        public ?string $externalId,
        public ?string $failureReason,
        public ?int $retryCount,
        public array $payload,
        public ?string $eventId = null,
        public ?string $type = null,
    ) {}
}
