<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Data;

use Carbon\CarbonImmutable;
use MemberFlow\Plorea\Data\Concerns\ParsesResponseData;

/**
 * The current stored payment state for a reference.
 */
final readonly class PaymentStatus
{
    use ParsesResponseData;

    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $reference,
        public ?string $tenantId,
        public ?string $platform,
        public ?string $orderId,
        public ?string $doneId,
        public ?string $status,
        public ?string $provider,
        public ?string $pspReference,
        public ?string $paymentLinkId,
        public ?string $paymentLinkUrl,
        public ?Amount $amount,
        public ?string $environment,
        public ?string $merchantAccount,
        public ?string $balanceAccountId,
        public ?string $store,
        public ?bool $splitsEnabled,
        public ?CarbonImmutable $createdAt,
        public ?CarbonImmutable $updatedAt,
        // The webhook* fields describe the inbound Adyen webhook Plorea
        // received for this payment, not the webhook Plorea sends to you.
        public ?string $webhookEventCode,
        public ?bool $webhookSuccess,
        public ?CarbonImmutable $lastWebhookAt,
        public ?string $lastRefundReference,
        public ?string $lastRefundRequestPspReference,
        public ?int $lastRefundAmount,
        public ?string $lastRefundReason,
        public ?CarbonImmutable $lastRefundRequestedAt,
        public ?string $lastCancelReference,
        public ?string $lastCancelRequestPspReference,
        public ?CarbonImmutable $lastCancelRequestedAt,
        public array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $value = self::int($data['amount'] ?? null);

        return new self(
            reference: self::string($data['reference'] ?? null) ?? '',
            tenantId: self::string($data['tenantId'] ?? null),
            platform: self::string($data['platform'] ?? null),
            orderId: self::string($data['orderId'] ?? null),
            doneId: self::string($data['doneId'] ?? null),
            status: self::string($data['status'] ?? null),
            provider: self::string($data['provider'] ?? null),
            pspReference: self::string($data['pspReference'] ?? null),
            paymentLinkId: self::string($data['paymentLinkId'] ?? null),
            paymentLinkUrl: self::string($data['paymentLinkUrl'] ?? null),
            amount: $value === null ? null : new Amount($value, self::string($data['currency'] ?? null) ?? 'NOK'),
            environment: self::string($data['environment'] ?? null),
            merchantAccount: self::string($data['merchantAccount'] ?? null),
            balanceAccountId: self::string($data['balanceAccountId'] ?? null),
            store: self::string($data['store'] ?? null),
            splitsEnabled: self::bool($data['splitsEnabled'] ?? null),
            createdAt: self::date($data['createdAt'] ?? null),
            updatedAt: self::date($data['updatedAt'] ?? null),
            webhookEventCode: self::string($data['webhookEventCode'] ?? null),
            webhookSuccess: self::bool($data['webhookSuccess'] ?? null),
            lastWebhookAt: self::date($data['lastWebhookAt'] ?? null),
            lastRefundReference: self::string($data['lastRefundReference'] ?? null),
            lastRefundRequestPspReference: self::string($data['lastRefundRequestPspReference'] ?? null),
            lastRefundAmount: self::int($data['lastRefundAmount'] ?? null),
            lastRefundReason: self::string($data['lastRefundReason'] ?? null),
            lastRefundRequestedAt: self::date($data['lastRefundRequestedAt'] ?? null),
            lastCancelReference: self::string($data['lastCancelReference'] ?? null),
            lastCancelRequestPspReference: self::string($data['lastCancelRequestPspReference'] ?? null),
            lastCancelRequestedAt: self::date($data['lastCancelRequestedAt'] ?? null),
            raw: $data,
        );
    }

    /**
     * Whether the payment status matches the given value.
     */
    public function is(string $status): bool
    {
        return $this->status !== null && strcasecmp($this->status, $status) === 0;
    }

    /**
     * Whether the payment has been authorised by the payment provider.
     */
    public function isAuthorised(): bool
    {
        return $this->is('authorised');
    }

    /**
     * Whether the payment has been completed. Test payments settle on
     * "authorised" while others report "paid" — both mean money moved.
     */
    public function isPaid(): bool
    {
        return $this->is('authorised') || $this->is('paid');
    }

    /**
     * Whether the payment link is still open and payable.
     */
    public function isOpen(): bool
    {
        return $this->is('created') || $this->is('pending') || $this->is('active');
    }
}
