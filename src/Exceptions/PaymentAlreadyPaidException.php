<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Exceptions;

use MemberFlow\Plorea\Data\PaymentStatus;

/**
 * Thrown by firstOrCreate() when a payment for the reference has already
 * completed. A resent link for a settled invoice is happily payable again,
 * so this is surfaced loudly instead of returning a new link.
 */
class PaymentAlreadyPaidException extends PloreaException
{
    public function __construct(public readonly PaymentStatus $status)
    {
        parent::__construct(
            "Payment [{$status->reference}] has already been paid (status: {$status->status}).",
        );
    }
}
