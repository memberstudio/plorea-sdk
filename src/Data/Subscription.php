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
     * @param  ?string  $merchantOrgNr  The company receiving the funds. Returned on create and on reads (2026-09-22); null when the subscription has none.
     * @param  ?string  $environment  "test" or "live", the environment the API key belongs to. Plorea added it to GET responses on 2026-10-13; null on older responses.
     * @param  ?CarbonImmutable  $currentPeriodEnd  The end of the period the last charge paid for. Plorea starts the next period here, so a late retry does not move the billing date (stated 2026-10-14). Verified in test 2026-10-14: equal to nextChargeAt on active, charged subscriptions; absent on trialing and canceled ones. Stated by Plorea 2026-10-20: null until the first charge, and also returned by the list.
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
        public ?string $environment = null,
        public ?CarbonImmutable $currentPeriodEnd = null,
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
            environment: self::string($data['environment'] ?? null),
            currentPeriodEnd: self::date($data['currentPeriodEnd'] ?? null),
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

    public function isPaused(): bool
    {
        return $this->is('paused');
    }

    public function isTrialing(): bool
    {
        return $this->is('trialing');
    }

    public function isCanceled(): bool
    {
        return $this->is('canceled');
    }

    /**
     * Whether a scheduled charge failed and Plorea is retrying it.
     *
     * Captured from production 2026-10-01: after a failed scheduler charge
     * the subscription reports past_due, retryCount counts the failed
     * attempts, failureReason carries the provider's message, and
     * nextChargeAt moves to the next retry (retryPolicy.retryIntervalDays
     * later). Because the date moves forward, a past_due subscription is not
     * overdue — check this status as well as isOverdue().
     */
    public function isPastDue(): bool
    {
        return $this->is('past_due');
    }

    /**
     * Whether the subscription reports payment_failed.
     *
     * Seen in production 6-8 October 2026, after three failed attempts about
     * 24 hours apart (retryCount 3, nextChargeAt null). Plorea stops charging
     * but has not confirmed that as a fixed policy. Earlier failures show as
     * past_due (see isPastDue()). Not reproduced in test. How a subscription
     * leaves this status is untested: resume is verified only from paused,
     * reactivate only from canceled.
     */
    public function hasPaymentFailure(): bool
    {
        return $this->is('payment_failed');
    }

    /**
     * Whether a scheduled charge is late.
     *
     * Plorea's scheduler charges within minutes of a natural nextChargeAt
     * (seconds in test, up to about 25 minutes in production) and moves the
     * date forward as it does, so a nextChargeAt still in the past after the
     * grace period means the cycle did not complete — a decline, a retry in
     * progress, or a subscription stuck for some other reason. A date moved by
     * hand on an active subscription has not been charged so far (open
     * question), so it also reads as overdue. This is derived entirely from
     * observed fields.
     *
     * A failed charge that Plorea is retrying is not overdue: the
     * subscription turns past_due and nextChargeAt moves to the retry. Use
     * isPastDue() for that case.
     *
     * Canceled and paused subscriptions are never overdue, even if a
     * response retains a stale nextChargeAt.
     *
     * Being overdue says the cycle did not complete, not why. Read
     * subscriptions()->charges() for that.
     */
    public function isOverdue(int $graceMinutes = 60, ?DateTimeInterface $now = null): bool
    {
        if (! $this->nextChargeAt instanceof CarbonImmutable || $this->isCanceled() || $this->isPaused()) {
            return false;
        }

        $reference = $now instanceof DateTimeInterface
            ? CarbonImmutable::instance($now)
            : CarbonImmutable::now();

        return $this->nextChargeAt->addMinutes($graceMinutes)->isBefore($reference);
    }
}
