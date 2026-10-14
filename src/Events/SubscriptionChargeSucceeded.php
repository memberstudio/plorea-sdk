<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Events;

/**
 * Dispatched for a `subscription.charge_succeeded` webhook — Plorea's
 * scheduler has charged the stored card for another billing period.
 *
 * Treat it as a ping like every other webhook: fetch the authoritative
 * subscription with Plorea::subscriptions()->find($subscriptionId) rather
 * than trusting the payload. Do not look the reference up through
 * payments()->status() — a scheduler-created charge does not resolve there
 * (403 "Tenant mismatch" on 2026-09-04, 404 "Payment not found" when
 * retested 2026-09-09). Read the charge from subscriptions()->charges().
 *
 * $eventId is Plorea's identifier for the delivery and is what you should
 * deduplicate on. Both it and $type come from the request body rather than
 * the headers, because the signature covers the body only.
 *
 * $reason is data.reason. Stated by Plorea 2026-10-14: a manual
 * subscriptions()->charge() now sends this event too, with reason
 * "manual_charge". Not yet captured; null when the payload has none.
 * Extend access only to the nextChargeAt of the subscription you read
 * with find(), while it is active; that is idempotent for a manual charge
 * too. Check isManualCharge() before you send a renewal
 * receipt or book a renewal.
 */
class SubscriptionChargeSucceeded
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $subscriptionId,
        public ?string $chargeId,
        public ?string $reference,
        public ?string $externalId,
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
