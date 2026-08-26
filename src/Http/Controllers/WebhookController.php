<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Http\Controllers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use MemberFlow\Plorea\Events\PaymentStatusUpdated;
use MemberFlow\Plorea\Events\WebhookReceived;

/**
 * Receives webhook calls from Plorea and dispatches events.
 *
 * Captured deliveries look like {eventId, createdAt, tenantId, type,
 * data: {reference, status, eventCode, success, ...}} with an event type
 * such as "payment.authorised". Still treat webhooks as a ping: listeners
 * should use the reference to fetch the authoritative state from the
 * status endpoint rather than trusting status or amount from the payload.
 */
class WebhookController
{
    public function __construct(protected Dispatcher $events) {}

    public function __invoke(Request $request): Response
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();

        $this->events->dispatch(new WebhookReceived($payload));

        $reference = $this->reference($payload);

        if ($reference !== null) {
            $this->events->dispatch(new PaymentStatusUpdated(
                $reference,
                $this->status($payload),
                $payload,
            ));
        }

        // Always acknowledge — an unparseable payload has nothing to retry.
        return new Response('[accepted]');
    }

    /**
     * The payment reference. Captured deliveries carry it in
     * "data.reference"; the top-level and "merchantReference" keys remain
     * as defensive fallbacks.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function reference(array $payload): ?string
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        $reference = $payload['reference']
            ?? $data['reference']
            ?? $payload['merchantReference']
            ?? null;

        return is_string($reference) && $reference !== '' ? $reference : null;
    }

    /**
     * The payment status carried by the payload. Captured deliveries put it
     * in "data.status" (with the Adyen event code in "data.eventCode" and a
     * "payment.*" event type at the top level); the flat keys remain as
     * defensive fallbacks.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function status(array $payload): ?string
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        $status = $payload['status']
            ?? $data['status']
            ?? $payload['eventCode']
            ?? $data['eventCode']
            ?? $payload['type']
            ?? null;

        return is_string($status) ? $status : null;
    }
}
