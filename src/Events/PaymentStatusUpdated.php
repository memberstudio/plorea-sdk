<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Events;

/**
 * Dispatched when a webhook carries a payment reference, typically after the
 * customer completes payment. Query Plorea::payments()->status($reference)
 * for the authoritative state.
 *
 * $eventId is Plorea's identifier for the delivery and is what you should
 * deduplicate on. $type is the catalogue type — "payment.authorised",
 * "payment.failed" or "payment.refunded". Both come from the request body
 * rather than the headers, because the signature covers the body only.
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
