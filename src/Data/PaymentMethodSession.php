<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Data;

use Carbon\CarbonImmutable;
use JsonSerializable;
use MemberFlow\Plorea\Data\Concerns\ParsesResponseData;
use MemberFlow\Plorea\Data\Concerns\ProvidesCheckoutConfiguration;
use MemberFlow\Plorea\Enums\RecurringType;

/**
 * An Adyen Sessions object for the Drop-in card setup flow.
 *
 * Hand `toCheckout()` to the Drop-in (web or native), then poll the payment
 * method until it becomes active — card setup emits no webhook. The DTO
 * serializes to `toCheckout()` and nothing else.
 */
final readonly class PaymentMethodSession implements JsonSerializable
{
    use ParsesResponseData;
    use ProvidesCheckoutConfiguration;

    /**
     * @param  ?string  $clientKey  The key from the response, else the configured `plorea.adyen_client_key`.
     * @param  ?string  $environment  The environment from the response, else the configured `plorea.environment`.
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $paymentMethodId,
        public ?string $tenantId,
        public ?string $customerId,
        public ?string $doneId,
        public ?string $shopperReference,
        public ?RecurringType $recurringType,
        public ?string $status,
        public ?string $environment,
        public string $sessionId,
        public ?string $sessionData,
        public ?CarbonImmutable $expiresAt,
        public ?string $clientKey = null,
        public array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, ?string $clientKey = null, ?string $environment = null): self
    {
        return new self(
            paymentMethodId: self::string($data['paymentMethodId'] ?? null) ?? '',
            tenantId: self::string($data['tenantId'] ?? null),
            customerId: self::string($data['customerId'] ?? null),
            doneId: self::string($data['doneId'] ?? null),
            shopperReference: self::string($data['shopperReference'] ?? null),
            recurringType: RecurringType::tryFrom(self::string($data['recurringType'] ?? null) ?? ''),
            status: self::string($data['status'] ?? null),
            environment: self::string($data['environment'] ?? null) ?? $environment,
            sessionId: self::string($data['sessionId'] ?? null) ?? '',
            sessionData: self::string($data['sessionData'] ?? null),
            expiresAt: self::date($data['expiresAt'] ?? null),
            clientKey: self::string($data['clientKey'] ?? null) ?? $clientKey,
            raw: $data,
        );
    }
}
