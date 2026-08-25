<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Data;

use Carbon\CarbonImmutable;
use MemberFlow\Plorea\Data\Concerns\ParsesResponseData;
use MemberFlow\Plorea\Enums\RecurringType;

/**
 * An Adyen Sessions object for the Web Drop-in card setup flow.
 *
 * Pass `sessionId` and `sessionData` to AdyenCheckout in the browser, then
 * poll the payment method until it becomes active.
 */
final readonly class PaymentMethodSession
{
    use ParsesResponseData;

    /**
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
        public array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            paymentMethodId: self::string($data['paymentMethodId'] ?? null) ?? '',
            tenantId: self::string($data['tenantId'] ?? null),
            customerId: self::string($data['customerId'] ?? null),
            doneId: self::string($data['doneId'] ?? null),
            shopperReference: self::string($data['shopperReference'] ?? null),
            recurringType: RecurringType::tryFrom(self::string($data['recurringType'] ?? null) ?? ''),
            status: self::string($data['status'] ?? null),
            environment: self::string($data['environment'] ?? null),
            sessionId: self::string($data['sessionId'] ?? null) ?? '',
            sessionData: self::string($data['sessionData'] ?? null),
            expiresAt: self::date($data['expiresAt'] ?? null),
            raw: $data,
        );
    }
}
