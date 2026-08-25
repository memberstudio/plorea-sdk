<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Http\Controllers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use MemberFlow\Plorea\Events\PaymentStatusUpdated;
use MemberFlow\Plorea\Events\WebhookReceived;

class WebhookController
{
    public function __construct(protected Dispatcher $events) {}

    public function __invoke(Request $request): Response
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();

        $this->events->dispatch(new WebhookReceived($payload));

        $reference = $payload['reference'] ?? $payload['merchantReference'] ?? null;

        if (is_string($reference) && $reference !== '') {
            $status = $payload['status'] ?? $payload['eventCode'] ?? null;

            $this->events->dispatch(new PaymentStatusUpdated(
                $reference,
                is_string($status) ? $status : null,
                $payload,
            ));
        }

        return new Response('[accepted]');
    }
}
