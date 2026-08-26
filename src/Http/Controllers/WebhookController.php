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
 * Treat webhooks as a ping: the payload shape is not firmly documented, so
 * listeners should use the reference to fetch the authoritative state from
 * the status endpoint rather than trusting status or amount from the payload.
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
            $status = $payload['status'] ?? $payload['eventCode'] ?? null;

            $this->events->dispatch(new PaymentStatusUpdated(
                $reference,
                is_string($status) ? $status : null,
                $payload,
            ));
        }

        // Always acknowledge — an unparseable payload has nothing to retry.
        return new Response('[accepted]');
    }

    /**
     * The payment reference. Plorea's outbound payload shape is undocumented
     * and unverified, so this defensively checks the likely keys:
     * "reference", "data.reference", and "merchantReference".
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
}
