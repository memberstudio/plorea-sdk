<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Data;

use Carbon\CarbonImmutable;
use MemberFlow\Plorea\Data\Concerns\ParsesResponseData;

/**
 * One row of the tenant's transaction list.
 *
 * The rows are payment links and card setups, newest first. A row is a
 * summary that can be stale: in test on 2026-11-09 a subscription-charge
 * row read `created` while payments()->status() for the same reference read
 * `authorised`. Read payments()->status() before you act on a row.
 */
final readonly class Transaction
{
    use ParsesResponseData;

    /**
     * @param  array<string, mixed>  $raw
     * @param  ?string  $platform  `unknown` on card-setup rows in test 2026-11-09.
     * @param  ?string  $paymentLinkUrl  A pay.plorea.no link, or an Adyen link for a card setup.
     * @param  ?string  $webhookEventCode  The last inbound Adyen event Plorea received, not a webhook it sent you.
     */
    public function __construct(
        public string $reference,
        public ?string $tenantId,
        public ?string $platform,
        public ?string $orderId,
        public ?string $doneId,
        public ?Amount $amount,
        public ?string $environment,
        public ?string $status,
        public ?string $provider,
        public ?string $pspReference,
        public ?string $paymentLinkId,
        public ?string $paymentLinkUrl,
        public ?string $merchantAccount,
        public ?string $store,
        public ?bool $splitsEnabled,
        public ?CarbonImmutable $createdAt,
        public ?CarbonImmutable $updatedAt,
        public ?string $webhookEventCode,
        public ?bool $webhookSuccess,
        public ?string $lastRefundReference,
        public ?int $lastRefundAmount,
        public ?string $lastRefundReason,
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
            amount: $value === null ? null : new Amount($value, self::string($data['currency'] ?? null) ?? 'NOK'),
            environment: self::string($data['environment'] ?? null),
            status: self::string($data['status'] ?? null),
            provider: self::string($data['provider'] ?? null),
            pspReference: self::string($data['pspReference'] ?? null),
            paymentLinkId: self::string($data['paymentLinkId'] ?? null),
            paymentLinkUrl: self::string($data['paymentLinkUrl'] ?? null),
            merchantAccount: self::string($data['merchantAccount'] ?? null),
            store: self::string($data['store'] ?? null),
            splitsEnabled: self::bool($data['splitsEnabled'] ?? null),
            createdAt: self::date($data['createdAt'] ?? null),
            updatedAt: self::date($data['updatedAt'] ?? null),
            webhookEventCode: self::string($data['webhookEventCode'] ?? null),
            webhookSuccess: self::bool($data['webhookSuccess'] ?? null),
            lastRefundReference: self::string($data['lastRefundReference'] ?? null),
            lastRefundAmount: self::int($data['lastRefundAmount'] ?? null),
            lastRefundReason: self::string($data['lastRefundReason'] ?? null),
            raw: $data,
        );
    }

    /**
     * Whether the transaction status matches the given value.
     */
    public function is(string $status): bool
    {
        return $this->status !== null && strcasecmp($this->status, $status) === 0;
    }

    /**
     * Whether this row is a subscription charge.
     *
     * Plorea documents that charges are not in this list, yet rows with a
     * `{subscriptionId}-{chargeId}` reference, platform `subscription` and no
     * payment link were in it in test on 2026-11-09, and their status was
     * stale. Skip them here and read subscriptions()->charges() for charges.
     * The reference alone is not enough: an app chooses its own link
     * references and can start one with `sub_`.
     */
    public function isSubscriptionCharge(): bool
    {
        return $this->paymentLinkId === null
            && preg_match('/^sub_[^-]+-chg_/', $this->reference) === 1;
    }

    /**
     * Whether this row is the small authorisation Plorea makes when a card
     * is stored: a `pmsetup_` reference on an Adyen link, not a
     * pay.plorea.no link (test 2026-11-09).
     */
    public function isCardSetup(): bool
    {
        return str_starts_with($this->reference, 'pmsetup_')
            && ! str_starts_with((string) $this->paymentLinkUrl, 'https://pay.plorea.no/');
    }
}
