<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Exceptions;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;

/**
 * Thrown when a subscription update moves nextChargeAt into a period the
 * customer has already paid for. Plorea answers 409 with paidUntil.
 *
 * Stated by Plorea 2026-10-14 and tested by Plorea in test; not yet
 * captured by us. Keep the date at or after paidUntil(), or send
 * allowEarlyCharge() when the customer agreed to pay again.
 */
class NextChargeInPaidPeriodException extends RequestException
{
    /**
     * The end of the paid period, or null when the body has no parsable date.
     */
    public function paidUntil(): ?CarbonImmutable
    {
        $paidUntil = $this->response?->json('paidUntil');

        if (! is_string($paidUntil) || $paidUntil === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($paidUntil);
        } catch (InvalidFormatException) {
            return null;
        }
    }
}
