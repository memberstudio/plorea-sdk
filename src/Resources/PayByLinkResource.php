<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Resources;

use MemberFlow\Plorea\Data\PaymentLink;
use MemberFlow\Plorea\Data\PaymentSession;
use MemberFlow\Plorea\Enums\Channel;

/**
 * The endpoints behind the pay.plorea.no payment page.
 *
 * `find()` is what the page renders from, and is the authoritative answer on
 * expiry. `session()` opens the same Adyen session the page mounts its Drop-in
 * on, so it is the entry point for rendering checkout on your own domain or in
 * a native app. The browser needs an origin-whitelisted client key from
 * Plorea (`plorea.client_key`); see docs/checkout.md.
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
     *
     * Pass a native `$channel` for Adyen's iOS or Android SDK; Plorea then
     * returns the client key in the response. Without one, nothing is sent
     * and Plorea treats the session as `Web`.
     */
    public function session(string $paymentLinkId, ?string $returnUrl = null, ?Channel $channel = null): PaymentSession
    {
        return PaymentSession::fromArray(
            $this->client->post('payments/session', $this->withoutNulls([
                'paymentLinkId' => $paymentLinkId,
                'returnUrl' => $returnUrl,
                'channel' => $channel?->value,
            ])),
            clientKey: $this->clientKey(),
            environment: $this->environment(),
        );
    }
}
