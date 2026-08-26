<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Resources;

use MemberFlow\Plorea\Data\PaymentLink;
use MemberFlow\Plorea\Data\PaymentSession;

/**
 * Unauthenticated endpoints used internally by the pay.plorea.no payment
 * page. Not intended for direct integration — included for completeness.
 */
class PayByLinkResource extends Resource
{
    /**
     * The payment data for a Plorea PayByLink, as rendered by pay.plorea.no.
     */
    public function find(string $paymentLinkId): PaymentLink
    {
        return PaymentLink::fromArray(
            $this->client->get('pay/'.rawurlencode($paymentLinkId)),
        );
    }

    /**
     * Create an Adyen Sessions object for a one-off payment. The session is
     * derived entirely from the referenced payment link.
     */
    public function session(string $paymentLinkId, ?string $returnUrl = null): PaymentSession
    {
        return PaymentSession::fromArray($this->client->post(
            'payments/session',
            $this->withoutNulls([
                'paymentLinkId' => $paymentLinkId,
                'returnUrl' => $returnUrl,
            ]),
        ));
    }
}
