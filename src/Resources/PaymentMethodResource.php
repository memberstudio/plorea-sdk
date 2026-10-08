<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Resources;

use MemberFlow\Plorea\Data\PaymentMethod;
use MemberFlow\Plorea\Data\PaymentMethodCancellation;
use MemberFlow\Plorea\Enums\RecurringType;
use MemberFlow\Plorea\Exceptions\PaymentMethodInUseException;
use MemberFlow\Plorea\Pending\PendingPaymentMethodSetup;

class PaymentMethodResource extends Resource
{
    /**
     * Start building a payment method setup for the customer to save a card.
     *
     * Finish with `create()` for the hosted Adyen page flow, or `session()`
     * for the embedded Drop-in flow (web or native, see `channel()`).
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
            $this->adyenClientKey(),
            $this->environment(),
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

    /**
     * Delete a stored payment method so it can never be charged again.
     *
     * Plorea keeps the record as `cancelled`; `find()` still returns it, card
     * details included. Repeating the call succeeds with `alreadyCancelled`.
     *
     * Another payment method for the same shopper can share its
     * `storedPaymentMethodId`; it stays `active` and can still be charged
     * (observed in the test environment).
     *
     * @throws PaymentMethodInUseException When subscriptions, trialing
     *                                     ones included, still use it.
     */
    public function delete(string $paymentMethodId): PaymentMethodCancellation
    {
        return PaymentMethodCancellation::fromArray(
            $this->client->delete('payment-methods/'.rawurlencode($paymentMethodId)),
        );
    }
}
