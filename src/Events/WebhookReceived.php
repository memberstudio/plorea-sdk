<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Events;

/**
 * Dispatched for every webhook call Plorea makes to your application,
 * regardless of its content.
 *
 * $eventId and $type are read from the request *body*, which is the only
 * part of the delivery the signature covers — see WebhookController.
 */
class WebhookReceived
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public array $payload,
        public ?string $eventId = null,
        public ?string $type = null,
    ) {}
}
