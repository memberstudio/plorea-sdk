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
