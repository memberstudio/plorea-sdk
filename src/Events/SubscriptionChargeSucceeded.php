<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Events;

/**
 * Dispatched for a `subscription.charge_succeeded` webhook — Plorea's
 * scheduler has charged the stored card for another billing period.
 *
 * Treat it as a ping like every other webhook: fetch the authoritative
 * subscription with Plorea::subscriptions()->find($subscriptionId) rather
 * than trusting the payload. Note that `payments()->status($reference)`
 * currently answers 403 for scheduler-created charges (a Plorea-side bug),
 * so read the charge itself from subscriptions()->charges() instead.
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
    ) {}
}
