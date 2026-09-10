<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Http\Controllers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use MemberFlow\Plorea\Events\PaymentStatusUpdated;
use MemberFlow\Plorea\Events\SubscriptionChargeSucceeded;
use MemberFlow\Plorea\Events\WebhookReceived;

/**
 * Receives webhook calls from Plorea and dispatches events.
 *
 * Captured deliveries look like {eventId, createdAt, tenantId, type,
 * data: {...}} with an event type such as "payment.authorised" or
 * "subscription.charge_succeeded". Still treat webhooks as a ping:
 * listeners should fetch the authoritative state from the API rather than
 * trusting status or amount from the payload.
 *
 * Plorea's official catalogue (confirmed by Plorea 2026-09-09) holds four
 * types: "payment.authorised", "payment.failed", "payment.refunded" and
 * "subscription.charge_succeeded", with more planned. The three payment
 * types all carry a reference and raise PaymentStatusUpdated.
 * "payment.authorised", "payment.failed" and
 * "subscription.charge_succeeded" have been captured from the wire; only
 * "payment.refunded" is still routed on the shared envelope rather than a
 * captured body.
 *
 * Nothing is emitted for card setup, cancel, reactivate, or a *failed*
 * scheduler charge — those transitions are poll-only, via
 * paymentMethods()->find(), subscriptions()->find() and
 * payments()->status(). Every delivery also dispatches the catch-all
 * WebhookReceived, which is how consumers handle types this controller
 * does not know about.
 *
 * Every event carries the delivery's eventId and type, read from the
 * *body*. Plorea duplicates both in the x-plorea-event-id and x-plorea-event
 * headers, but the signature is computed over the raw body alone, so the
 * headers are the one part of a verified delivery an attacker can still
 * rewrite. Deduplicate on $event->eventId, never on the header.
 */
class WebhookController
{
    public function __construct(protected Dispatcher $events) {}

    public function __invoke(Request $request): Response
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();

        $type = is_string($payload['type'] ?? null) ? $payload['type'] : '';
        $eventId = $this->stringOrNull($payload['eventId'] ?? null);

        $this->events->dispatch(new WebhookReceived($payload, $eventId, $type === '' ? null : $type));

        if (str_starts_with($type, 'subscription.')) {
            $this->dispatchSubscriptionEvent($type, $payload, $eventId);

            // Always acknowledge — an unparseable payload has nothing to retry.
            return new Response('[accepted]');
        }

        $reference = $this->reference($payload);

        if ($reference !== null) {
            $this->events->dispatch(new PaymentStatusUpdated(
                $reference,
                $this->status($payload),
                $payload,
                $eventId,
                $type === '' ? null : $type,
            ));
        }

        return new Response('[accepted]');
    }

    /**
     * Dispatch the event for a "subscription.*" delivery.
     *
     * Subscription payloads carry a "data.reference" of
     * {subscriptionId}-{chargeId}, but it must not also raise
     * PaymentStatusUpdated: a scheduler charge is subscription state, and
     * firing both events would have every listener book the same charge
     * twice. Consumers read the outcome through subscriptions()->find() or
     * charges(). That reference cannot be resolved through the payment
     * status endpoint anyway: it answered 403 "Tenant mismatch" on
     * 2026-09-04 and 404 "Payment not found" when retested against a fresh
     * authorised charge on 2026-09-09. Manual charges do resolve there.
     *
     * "subscription.charge_succeeded" is the only subscription type in
     * Plorea's catalogue, so any other subscription type reaches consumers
     * through WebhookReceived alone rather than through an event built on
     * a guessed shape.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function dispatchSubscriptionEvent(string $type, array $payload, ?string $eventId): void
    {
        if ($type !== 'subscription.charge_succeeded') {
            return;
        }

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        $subscriptionId = $data['subscriptionId'] ?? null;

        if (! is_string($subscriptionId) || $subscriptionId === '') {
            return;
        }

        $this->events->dispatch(new SubscriptionChargeSucceeded(
            $subscriptionId,
            $this->stringOrNull($data['chargeId'] ?? null),
            $this->stringOrNull($data['reference'] ?? null),
            $this->stringOrNull($data['externalId'] ?? null),
            $payload,
            $eventId,
            $type,
        ));
    }

    /**
     * A non-empty string from the payload, or null for anything else.
     */
    protected function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
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
