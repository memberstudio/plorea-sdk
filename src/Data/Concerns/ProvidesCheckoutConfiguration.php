<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Data\Concerns;

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
}
