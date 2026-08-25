<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Events;

/**
 * Dispatched when a webhook carries a payment reference, typically after the
 * customer completes payment. Query Plorea::payments()->status($reference)
 * for the authoritative state.
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
    ) {}
}
