<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Events;

/**
 * Dispatched when a webhook carries a payment reference, typically after the
 * customer completes payment. Query Plorea::payments()->status($reference)
 * for the authoritative state.
 *
 * $eventId is Plorea's identifier for the delivery and is what you should
 * deduplicate on. $type is the catalogue type, for example
 * "payment.authorised", "payment.failed", "payment.refunded",
 * "payment.refund_failed", "payment.cancelled" or "payment.updated". Both
 * come from the request body rather than the headers, because the
 * signature covers the body only.
 *
 * Every "payment.*" type raises this event. "payment.updated" carries
 * chargebacks and split refunds; its `data.status` says which. Do not
 * branch on $type alone: read the status from the API.
 */
class PaymentStatusUpdated
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $reference,
        public ?string $status,
        public array $payload,
        public ?string $eventId = null,
        public ?string $type = null,
    ) {}
}
