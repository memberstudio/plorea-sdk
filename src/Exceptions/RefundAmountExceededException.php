<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Exceptions;

use MemberFlow\Plorea\Data\Amount;

/**
 * Thrown when a refund would take the refunded total past the original
 * payment amount.
 *
 * Captured in test 2026-11-09 on a payment link and on a subscription
 * charge: 400 "Refund amount exceeds original payment amount" with
 * `originalAmount`, `alreadyRefunded`, `requestedAmount`, `remainingAmount`
 * and `currency`. Before November 2026 the same refusal was a 422.
 */
class RefundAmountExceededException extends ValidationException
{
    /**
     * What can still be refunded.
     */
    public function remainingAmount(): ?Amount
    {
        return $this->amount('remainingAmount');
    }

    /**
     * What has been refunded already.
     */
    public function alreadyRefunded(): ?Amount
    {
        return $this->amount('alreadyRefunded');
    }

    /**
     * The amount this refund asked for.
     */
    public function requestedAmount(): ?Amount
    {
        return $this->amount('requestedAmount');
    }

    /**
     * The amount of the payment itself.
     */
    public function originalAmount(): ?Amount
    {
        return $this->amount('originalAmount');
    }

    private function amount(string $key): ?Amount
    {
        $value = $this->response?->json($key);
        $currency = $this->response?->json('currency');

        return is_numeric($value)
            ? new Amount((int) $value, is_string($currency) && $currency !== '' ? $currency : 'NOK')
            : null;
    }
}
