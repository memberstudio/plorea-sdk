<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Data;

use JsonSerializable;
use MemberFlow\Plorea\Data\Concerns\ParsesResponseData;
use MemberFlow\Plorea\Data\Concerns\ProvidesCheckoutConfiguration;

/**
 * An Adyen Sessions object for a one-off payment, used by pay.plorea.no.
 *
 * Hand `toCheckout()` to the Drop-in. It serializes to that and nothing else.
 */
final readonly class PaymentSession implements JsonSerializable
{
    use ParsesResponseData;
    use ProvidesCheckoutConfiguration;

    /**
     * @param  ?string  $clientKey  The key from the response (native channels), else the configured `plorea.client_key`.
     * @param  ?string  $environment  The environment from the response, else the configured `plorea.environment`.
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $sessionId,
        public ?string $sessionData,
        public ?string $environment,
        public ?string $clientKey,
        public array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, ?string $clientKey = null, ?string $environment = null): self
    {
        return new self(
            sessionId: self::string($data['sessionId'] ?? null) ?? '',
            sessionData: self::string($data['sessionData'] ?? null),
            environment: self::string($data['environment'] ?? null) ?? $environment,
            clientKey: self::string($data['clientKey'] ?? null) ?? $clientKey,
            raw: $data,
        );
    }
}
