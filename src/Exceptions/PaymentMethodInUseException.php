<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Exceptions;

/**
 * Thrown when a payment method cannot be deleted because subscriptions still
 * bill it. A trialing subscription counts too. Move or cancel the listed
 * subscriptions first; the payment method stays active until then.
 */
class PaymentMethodInUseException extends ValidationException
{
    /**
     * The ids of the subscriptions that still use the payment method.
     *
     * @return list<string>
     */
    public function activeSubscriptionIds(): array
    {
        $ids = $this->response?->json('activeSubscriptionIds');

        return is_array($ids) ? array_values(array_filter($ids, is_string(...))) : [];
    }

    /**
     * The blocking subscriptions as Plorea lists them: `subscriptionId`,
     * `status`, `nextChargeAt` and `title`.
     *
     * @return list<array<string, mixed>>
     */
    public function activeSubscriptions(): array
    {
        $subscriptions = $this->response?->json('activeSubscriptions');

        return is_array($subscriptions) ? array_values(array_filter($subscriptions, is_array(...))) : [];
    }
}
