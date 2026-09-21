<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Data;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use MemberFlow\Plorea\Data\Concerns\ParsesResponseData;
use MemberFlow\Plorea\Enums\RecurringType;

/**
 * A Plorea subscription.
 */
final readonly class Subscription
{
    use ParsesResponseData;

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $raw
     * @param  ?string  $merchantOrgNr  The company receiving the funds. Returned on create only (2026-09-21); null on every read.
     */
    public function __construct(
        public string $id,
        public ?string $tenantId,
        public ?string $customerId,
        public ?string $doneId,
        public ?string $paymentMethodId,
        public ?string $shopperReference,
        public ?string $storedPaymentMethodId,
        public ?RecurringType $recurringType,
        public ?Amount $amount,
        public ?int $quantity,
        public ?string $externalId,
        public ?string $title,
        public ?string $description,
        public ?float $vatRate,
        public ?int $vatAmount,
        public ?BillingInterval $interval,
        public ?CarbonImmutable $trialEndsAt,
        public ?CarbonImmutable $nextChargeAt,
        public ?RetryPolicy $retryPolicy,
        public ?int $retryCount,
        public ?CarbonImmutable $lastChargeAt,
        public ?string $lastPaymentReference,
        public ?string $failureReason,
        public ?CarbonImmutable $canceledAt,
        public ?string $cancelReason,
        public ?CarbonImmutable $accessEndsAt,
        public ?string $status,
        public array $metadata,
        public ?CarbonImmutable $createdAt,
        public ?CarbonImmutable $updatedAt,
        public array $raw = [],
        public ?string $merchantOrgNr = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: self::string($data['subscriptionId'] ?? null) ?? '',
            tenantId: self::string($data['tenantId'] ?? null),
            customerId: self::string($data['customerId'] ?? null),
            doneId: self::string($data['doneId'] ?? null),
            paymentMethodId: self::string($data['paymentMethodId'] ?? null),
            shopperReference: self::string($data['shopperReference'] ?? null),
            storedPaymentMethodId: self::string($data['storedPaymentMethodId'] ?? null),
            recurringType: RecurringType::tryFrom(self::string($data['recurringType'] ?? null) ?? ''),
            amount: Amount::fromArray(is_array($data['amount'] ?? null) ? $data['amount'] : null),
            quantity: self::int($data['quantity'] ?? null),
            externalId: self::string($data['externalId'] ?? null),
            title: self::string($data['title'] ?? null),
            description: self::string($data['description'] ?? null),
            vatRate: self::float($data['vatRate'] ?? null),
            vatAmount: self::int($data['vatAmount'] ?? null),
            interval: BillingInterval::fromArray(is_array($data['interval'] ?? null) ? $data['interval'] : null),
            trialEndsAt: self::date($data['trialEndsAt'] ?? null),
            nextChargeAt: self::date($data['nextChargeAt'] ?? null),
            retryPolicy: RetryPolicy::fromArray(is_array($data['retryPolicy'] ?? null) ? $data['retryPolicy'] : null),
            retryCount: self::int($data['retryCount'] ?? null),
            lastChargeAt: self::date($data['lastChargeAt'] ?? null),
            lastPaymentReference: self::string($data['lastPaymentReference'] ?? null),
            failureReason: self::string($data['failureReason'] ?? null),
            canceledAt: self::date($data['canceledAt'] ?? null),
            cancelReason: self::string($data['cancelReason'] ?? null),
            accessEndsAt: self::date($data['accessEndsAt'] ?? null),
            status: self::string($data['status'] ?? null),
            metadata: self::metadata($data['metadata'] ?? null),
            createdAt: self::date($data['createdAt'] ?? null),
            updatedAt: self::date($data['updatedAt'] ?? null),
            raw: $data,
            merchantOrgNr: self::string($data['merchantOrgNr'] ?? null),
        );
    }

    /**
     * Whether the subscription status matches the given value.
     */
    public function is(string $status): bool
    {
        return $this->status !== null && strcasecmp($this->status, $status) === 0;
    }

    public function isActive(): bool
    {
        return $this->is('active');
    }

    public function isTrialing(): bool
    {
        return $this->is('trialing');
    }

    public function isCanceled(): bool
    {
        return $this->is('canceled');
    }

    public function hasPaymentFailure(): bool
    {
        return $this->is('payment_failed');
    }

    /**
     * Whether a scheduled charge is late.
     *
     * Plorea's scheduler charges within seconds of nextChargeAt and moves the
     * date forward as it does, so a nextChargeAt still in the past after the
     * grace period means the cycle did not complete — a decline, a retry in
     * progress, or a subscription stuck for some other reason. This is
     * derived entirely from observed fields, which is why dunning should lean
     * on it rather than on the never-observed payment_failed status.
     *
     * A canceled subscription is never overdue: it keeps whatever
     * nextChargeAt it had when scheduling stopped.
     *
     * Being overdue says the cycle did not complete, not why. Read
     * subscriptions()->charges() for that.
     */
    public function isOverdue(int $graceMinutes = 60, ?DateTimeInterface $now = null): bool
    {
        if (! $this->nextChargeAt instanceof CarbonImmutable || $this->isCanceled()) {
            return false;
        }

        $reference = $now instanceof DateTimeInterface
            ? CarbonImmutable::instance($now)
            : CarbonImmutable::now();

        return $this->nextChargeAt->addMinutes($graceMinutes)->isBefore($reference);
    }
}
