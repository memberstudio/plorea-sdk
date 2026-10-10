<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Data\Concerns;

use MemberFlow\Plorea\Exceptions\PloreaException;

/**
 * The frontend-safe view of an Adyen session.
 *
 * A session DTO also carries the full API response in `raw` — tenant, shopper
 * reference, customer id — none of which belongs in a browser or an app. This
 * is the only part of it a client needs, and it is what the DTO serializes to,
 * so `response()->json($session)` cannot leak the rest.
 *
 * @property-read string $sessionId
 * @property-read ?string $sessionData
 * @property-read ?string $clientKey
 * @property-read ?string $environment
 */
trait ProvidesCheckoutConfiguration
{
    /**
     * Everything Adyen's Drop-in (web or native) needs to mount this session.
     *
     * @return array{sessionId: string, sessionData: ?string, clientKey: ?string, environment: ?string}
     */
    public function toCheckout(): array
    {
        return [
            'sessionId' => $this->sessionId,
            'sessionData' => $this->sessionData,
            'clientKey' => $this->clientKey,
            'environment' => $this->environment,
        ];
    }

    /**
     * @return array{sessionId: string, sessionData: ?string, clientKey: ?string, environment: ?string}
     */
    public function jsonSerialize(): array
    {
        return $this->toCheckout();
    }

    /**
     * The session id and data from a response. Plorea sends `sessionId` and
     * `sessionData`; Adyen's own names are `id` and `data`, and Plorea's pay
     * page reads both (2026-09-14), so accept either. A response with neither
     * id throws here: an empty id fails late, inside Drop-in.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: ?string}
     */
    protected static function sessionFields(array $data): array
    {
        $field = static fn (string ...$keys): ?string => array_reduce(
            $keys,
            fn (?string $found, string $key): ?string => $found ?? (is_string($data[$key] ?? null) && $data[$key] !== '' ? $data[$key] : null),
        );

        $sessionId = $field('sessionId', 'id') ?? throw new PloreaException(
            'Plorea returned a checkout session without a session id. Expected sessionId (or id), got: ['.implode(', ', array_keys($data)).'].',
        );

        return [$sessionId, $field('sessionData', 'data')];
    }
}
