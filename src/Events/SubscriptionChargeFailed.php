<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Events;

/**
 * Dispatched for a `subscription.charge_failed` webhook — Plorea's
 * scheduler tried to charge the stored card and failed. The subscription
 * normally moves to past_due, and Plorea retries on its own schedule.
 *
 * Observed 2026-10-01 to 2026-10-08: one delivery per attempt, the last
 * retry included, with retryCount 1, 2, 3. Every one so far carried
 * failureReason "PaymentDetail not found" — per Plorea (2026-10-09), their
 * scheduler had charged live subscriptions against Adyen's test
 * environment; fixed 2026-10-09. Unlike the success event, the payload
 * carries no pspReference and no nextChargeAt.
 *
 * These were live charges, but Plorea delivered them to the test URL with
 * data.environment "test" (observed 2026-10-08). Plorea states the routing
 * is fixed as of 2026-10-09 (live URL, environment "live"); not yet
 * verified. Until it is, do not trust data.environment on a subscription.*
 * delivery.
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
 *
 * $reason is data.reason. Stated by Plorea 2026-10-14: a manual
 * subscriptions()->charge() now sends this event too, with reason
 * "manual_charge". Not yet captured; null when the payload has none.
 * A failure never extends access: after a failed charge, nextChargeAt can
 * be the next retry, not a paid-through date. Check isManualCharge() to
 * tell a refused manual charge from a failed renewal before you start
 * dunning.
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
        public ?string $reason = null,
    ) {}

    /**
     * Whether a manual subscriptions()->charge() sent this event, rather than
     * the scheduler.
     */
    public function isManualCharge(): bool
    {
        return $this->reason === 'manual_charge';
    }
}
