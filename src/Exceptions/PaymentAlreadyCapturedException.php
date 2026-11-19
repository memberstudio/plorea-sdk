<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Exceptions;

/**
 * Thrown when payments()->cancel() is sent for a payment that has already
 * been captured. Plorea answers 409; refund the payment instead.
 *
 * Captured in test 2026-11-09 for a paid payment link ("Payment has been
 * captured; use /payments/refund") and for a subscription charge ("Charge has
 * been captured; use refund"). Both bodies carry `reference`, `status`,
 * `pspReference` and `refundedTotal` (null when nothing is refunded); the
 * charge adds `subscriptionId` and `chargeId`. Before November 2026 the same
 * cancel on a charge was accepted and then refused by Adyen.
 */
class PaymentAlreadyCapturedException extends RequestException
{
    /**
     * The payment status Plorea reported, for example `authorised`.
     */
    public function paymentStatus(): ?string
    {
        $status = $this->response?->json('status');

        return is_string($status) ? $status : null;
    }
}
