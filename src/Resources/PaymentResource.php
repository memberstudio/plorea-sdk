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
     *     ->payerEmail('kunde@eksempel.no')
     *     ->merchant(orgNr: '912650774', name: 'Techify AS', email: 'post@techify.no')
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
     */
    public function status(string $reference): PaymentStatus
    {
        return PaymentStatus::fromArray(
            $this->client->get('payments/status/'.rawurlencode($reference)),
        );
    }

    /**
     * Request a refund for an existing payment.
     *
     * @param  string  $modificationReference  Your unique reference for this refund attempt.
     * @param  Amount|int|null  $amount  Refund amount in minor units. Refunds the full payment amount when omitted.
     */
    public function refund(
        string $reference,
        string $modificationReference,
        Amount|int|null $amount = null,
        ?string $reason = null,
    ): Refund {
        return Refund::fromArray($this->client->post('payments/refund', $this->withoutNulls([
            'reference' => $reference,
            'modificationReference' => $modificationReference,
            'amount' => $amount instanceof Amount ? $amount->value : $amount,
            'reason' => $reason,
        ])));
    }

    /**
     * Request cancellation of an existing payment.
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
