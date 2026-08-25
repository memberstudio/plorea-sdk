<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Data;

use MemberFlow\Plorea\Data\Concerns\ParsesResponseData;

/**
 * An Adyen Sessions object for a one-off payment, used by pay.plorea.no.
 */
final readonly class PaymentSession
{
    use ParsesResponseData;

    /**
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
    public static function fromArray(array $data): self
    {
        return new self(
            sessionId: self::string($data['sessionId'] ?? null) ?? '',
            sessionData: self::string($data['sessionData'] ?? null),
            environment: self::string($data['environment'] ?? null),
            clientKey: self::string($data['clientKey'] ?? null),
            raw: $data,
        );
    }
}
