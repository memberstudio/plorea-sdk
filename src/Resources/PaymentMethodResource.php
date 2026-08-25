<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Resources;

use MemberFlow\Plorea\Data\PaymentMethod;
use MemberFlow\Plorea\Enums\RecurringType;
use MemberFlow\Plorea\Pending\PendingPaymentMethodSetup;

class PaymentMethodResource extends Resource
{
    /**
     * Start building a payment method setup for the customer to save a card.
     *
     * Finish with `create()` for the hosted Adyen page flow, or `session()`
     * for the embedded Web Drop-in flow.
     *
     * ```php
     * $method = Plorea::paymentMethods()
     *     ->setup('customer-123', RecurringType::Subscription, 'https://app.test/billing/return')
     *     ->customerId('cust_123')
     *     ->create();
     *
     * return redirect($method->adyenPaymentLinkUrl);
     * ```
     */
    public function setup(
        string $shopperReference,
        RecurringType $recurringType,
        string $returnUrl,
    ): PendingPaymentMethodSetup {
        return new PendingPaymentMethodSetup(
            $this->client,
            $this->tenantId(),
            $shopperReference,
            $recurringType,
            $returnUrl,
        );
    }

    /**
     * The current stored state for a payment method.
     *
     * Poll this after Drop-in completion until `status` is "active".
     */
    public function find(string $paymentMethodId): PaymentMethod
    {
        return PaymentMethod::fromArray(
            $this->client->get('payment-methods/'.rawurlencode($paymentMethodId)),
        );
    }
}
