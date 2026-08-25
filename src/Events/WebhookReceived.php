<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Events;

/**
 * Dispatched for every webhook call Plorea makes to your application,
 * regardless of its content.
 */
class WebhookReceived
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public array $payload) {}
}
