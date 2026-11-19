<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Exceptions;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;

/**
 * Thrown when a payment link has been cancelled.
 *
 * Captured in test 2026-11-09: payByLink()->find() answers 410 and
 * payByLink()->session() answers 400, both "Payment link was cancelled" with
 * `cancelledAt`. It extends ValidationException so code that already caught
 * the 400 keeps working.
 */
class PaymentLinkCancelledException extends ValidationException
{
    /**
     * When the link was cancelled, or null when the body has no parsable date.
     */
    public function cancelledAt(): ?CarbonImmutable
    {
        $cancelledAt = $this->response?->json('cancelledAt');

        if (! is_string($cancelledAt) || $cancelledAt === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($cancelledAt);
        } catch (InvalidFormatException) {
            return null;
        }
    }
}
