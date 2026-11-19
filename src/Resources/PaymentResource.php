<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Resources;

use MemberFlow\Plorea\Data\Amount;
use MemberFlow\Plorea\Data\PaymentCancellation;
use MemberFlow\Plorea\Data\PaymentStatus;
use MemberFlow\Plorea\Data\Refund;
use MemberFlow\Plorea\Enums\Environment;
use MemberFlow\Plorea\Pending\PendingPaymentLink;

class PaymentResource extends Resource
{
    /**
     * Start building a payment link.
     *
     * ```php
     * $link = Plorea::payments()
     *     ->link('FIN-2026-00123', 'Faktura FIN-2026-00123', Amount::nok(450000), 'https://app.test/paid')
     *     ->payerEmail('customer@example.com')
     *     ->merchant(orgNr: '999999999', name: 'Acme Gym AS', email: 'billing@example.com')
     *     ->create();
     * ```
     */
    public function link(string $reference, string $product, Amount $amount, string $returnUrl): PendingPaymentLink
    {
        $environment = $this->config['environment'] ?? null;

        if ($environment instanceof Environment) {
            $environment = $environment->value;
        }

        return new PendingPaymentLink(
            $this->client,
            $this->tenantId(),
            $this->platform(),
            $reference,
            $product,
            $amount,
            $returnUrl,
            is_string($environment) && $environment !== '' ? $environment : null,
        );
    }

    /**
     * The current stored payment state for a reference.
     *
     * Pass `$tenantId` to make Plorea check that the payment belongs to that
     * tenant: a payment of another tenant then answers 404 (verified
     * 2026-11-09, test). Without it the key's own tenants are searched.
     */
    public function status(string $reference, ?string $tenantId = null): PaymentStatus
    {
        return PaymentStatus::fromArray(
            $this->client->get('payments/status/'.rawurlencode($reference), $this->withoutNulls([
                'tenantId' => $tenantId,
            ])),
        );
    }

    /**
     * Request a refund for an existing payment.
     *
     * A partial or full refund on a payment that reads "authorised" is a
     * refund, not a cancel (verified 2026-10-09, test). "refund_requested"
     * means accepted: settlement is asynchronous and its final shape is
     * unobserved, so do not book the refund as final on this response.
     *
     * A subscription charge refunds under its `{subscriptionId}-{chargeId}`
     * reference (verified 2026-10-19, test). Its status reports the refund in
     * `refunds` and `refundedTotal`, not in the `lastRefund*` fields.
     *
     * @param  string  $modificationReference  Your unique reference for this refund attempt.
     *                                         A refund above what is left gets 400 with the amounts, raised as
     *                                         RefundAmountExceededException (verified 2026-11-09, test; it was 422
     *                                         before). Read what is left first through status()->refundable.
     * @param  Amount|int|null  $amount  Refund amount in minor units. Refunds the full payment amount when omitted.
     * @param  ?string  $tenantId  Narrows the lookup to one tenant; a payment of another tenant then answers 404.
     */
    public function refund(
        string $reference,
        string $modificationReference,
        Amount|int|null $amount = null,
        ?string $reason = null,
        ?string $tenantId = null,
    ): Refund {
        return Refund::fromArray($this->client->post('payments/refund', $this->withoutNulls([
            'reference' => $reference,
            'modificationReference' => $modificationReference,
            'amount' => $amount instanceof Amount ? $amount->value : $amount,
            'reason' => $reason,
            'tenantId' => $tenantId,
        ])));
    }

    /**
     * Request cancellation of an existing payment.
     *
     * Only an unpaid payment link or an unsettled authorisation can be
     * cancelled. A cancel on an unpaid link withdraws the link: the answer
     * reads `cancelled` with `paymentLinkId` and `cancelledAt` (see
     * PaymentCancellation::isLinkWithdrawn()), and the link then answers 410.
     *
     * A captured payment or subscription charge answers 409, raised as
     * PaymentAlreadyCapturedException: refund it instead (verified
     * 2026-11-09, test). Before that change, in test on 2026-10-19, the
     * cancel of a charge was accepted, Adyen refused it, and the status
     * still became `cancelled` while the money stayed charged. See
     * PaymentStatus::isCancellationRefused() for rows from that time.
     *
     * @param  string  $modificationReference  Your unique reference for this cancellation attempt.
     */
    public function cancel(string $reference, string $modificationReference): PaymentCancellation
    {
        return PaymentCancellation::fromArray($this->client->post('payments/cancel', [
            'reference' => $reference,
            'modificationReference' => $modificationReference,
        ]));
    }
}
