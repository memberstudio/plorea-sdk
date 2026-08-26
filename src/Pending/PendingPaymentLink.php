<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Pending;

use MemberFlow\Plorea\Concerns\FiltersNullValues;
use MemberFlow\Plorea\Contracts\Client;
use MemberFlow\Plorea\Data\Amount;
use MemberFlow\Plorea\Data\PaymentLink;
use MemberFlow\Plorea\Data\PaymentLinkCreated;
use MemberFlow\Plorea\Data\PaymentStatus;
use MemberFlow\Plorea\Exceptions\NotFoundException;
use MemberFlow\Plorea\Exceptions\PaymentAlreadyPaidException;
use MemberFlow\Plorea\Exceptions\PloreaException;
use MemberFlow\Plorea\Exceptions\RequestException;

/**
 * Fluently configures and creates a payment link.
 */
class PendingPaymentLink
{
    use FiltersNullValues;

    protected ?string $orderId = null;

    protected ?string $doneId = null;

    protected ?string $payerEmail = null;

    protected ?string $invoiceUrl = null;

    protected ?bool $enableSplits = null;

    protected ?string $store = null;

    protected ?string $merchantOrgNr = null;

    protected ?string $merchantName = null;

    protected ?string $merchantCountry = null;

    protected ?string $merchantEmail = null;

    protected ?string $merchantPhone = null;

    public function __construct(
        protected readonly Client $client,
        protected string $tenantId,
        protected ?string $platform,
        protected string $reference,
        protected readonly string $product,
        protected readonly Amount $amount,
        protected readonly string $returnUrl,
        protected readonly ?string $environment = null,
    ) {}

    /**
     * Override the tenant from the package configuration.
     */
    public function tenant(string $tenantId): static
    {
        $this->tenantId = $tenantId;

        return $this;
    }

    /**
     * The platform identifier included with the payment link.
     */
    public function platform(string $platform): static
    {
        $this->platform = $platform;

        return $this;
    }

    /**
     * Your own order identifier grouping related payment attempts.
     */
    public function orderId(string $orderId): static
    {
        $this->orderId = $orderId;

        return $this;
    }

    /**
     * The universal Done user identifier linked to this payment.
     */
    public function doneId(string $doneId): static
    {
        $this->doneId = $doneId;

        return $this;
    }

    /**
     * The payer's email address, used by Adyen to send a payment confirmation.
     */
    public function payerEmail(string $email): static
    {
        $this->payerEmail = $email;

        return $this;
    }

    /**
     * A link to the underlying invoice PDF, shown on the payment page.
     */
    public function invoiceUrl(string $url): static
    {
        $this->invoiceUrl = $url;

        return $this;
    }

    /**
     * The invoice issuer receiving the funds. Plorea starts KYC automatically
     * for merchants it has not seen before.
     */
    public function merchant(
        string $orgNr,
        ?string $name = null,
        ?string $email = null,
        ?string $phone = null,
        ?string $country = null,
    ): static {
        $this->merchantOrgNr = $orgNr;
        $this->merchantName = $name;
        $this->merchantEmail = $email;
        $this->merchantPhone = $phone;
        $this->merchantCountry = $country;

        return $this;
    }

    /**
     * Route the invoice amount directly to the merchant's store via splits.
     */
    public function enableSplits(bool $enabled = true): static
    {
        $this->enableSplits = $enabled;

        return $this;
    }

    /**
     * Use a specific Adyen store instead of the default tenant store.
     */
    public function store(string $store): static
    {
        $this->store = $store;

        return $this;
    }

    /**
     * Create the payment link.
     */
    public function create(): PaymentLinkCreated
    {
        return PaymentLinkCreated::fromArray(
            $this->client->post('payments/link', $this->toPayload()),
        );
    }

    /**
     * Reuse an existing open link for the reference, or create one.
     *
     * Plorea has no idempotency on duplicate references, so this checks the
     * stored payment state first: an open, unexpired link with the same
     * amount and environment is returned as-is; a dead link (expired,
     * cancelled, refunded) or an open link with a different amount is
     * superseded by a new link with a suffixed reference ("{reference}-1",
     * "-2", ...). A reference that has already been paid throws, since a
     * fresh link for a settled invoice would be payable again.
     *
     * @throws PaymentAlreadyPaidException When the payment has already completed.
     */
    public function firstOrCreate(int $maxAttempts = 10): PaymentLinkCreated
    {
        $base = $this->reference;

        for ($attempt = 0; $attempt <= $maxAttempts; $attempt++) {
            $this->reference = $attempt === 0 ? $base : "{$base}-{$attempt}";

            try {
                $status = PaymentStatus::fromArray(
                    $this->client->get('payments/status/'.rawurlencode($this->reference)),
                );
            } catch (NotFoundException) {
                return $this->create();
            }

            if ($status->isPaid()) {
                throw new PaymentAlreadyPaidException($status);
            }

            if ($this->isReusable($status)) {
                return PaymentLinkCreated::fromArray($status->raw);
            }
        }

        throw new PloreaException(
            "Unable to find or create a payment link for [{$base}] within {$maxAttempts} reference suffixes.",
        );
    }

    /**
     * Whether the stored payment behind an existing reference can be handed
     * out again instead of creating a new link.
     */
    protected function isReusable(PaymentStatus $status): bool
    {
        if (! $status->isOpen()
            || $status->amount?->value !== $this->amount->value
            || $status->paymentLinkUrl === null
            || $status->paymentLinkId === null
        ) {
            return false;
        }

        if ($this->environment !== null
            && $status->environment !== null
            && strcasecmp($this->environment, $status->environment) !== 0
        ) {
            return false;
        }

        return ! $this->hasExpired($status->paymentLinkId);
    }

    /**
     * The status endpoint exposes no expiry and its status string has not
     * been observed to flip to "expired", so expiry has to come from the
     * link itself.
     */
    protected function hasExpired(string $paymentLinkId): bool
    {
        try {
            $link = PaymentLink::fromArray(
                $this->client->get('pay/'.rawurlencode($paymentLinkId)),
            );
        } catch (RequestException) {
            // The link lookup is a best-effort guard; when it is unavailable,
            // fall back to trusting the open status.
            return false;
        }

        return $link->expired || $link->expiresAt?->isPast() === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return $this->withoutNulls([
            'platform' => $this->platform,
            'tenantId' => $this->tenantId,
            'doneId' => $this->doneId,
            'orderId' => $this->orderId,
            'reference' => $this->reference,
            'product' => $this->product,
            'amount' => $this->amount->value,
            'currency' => $this->amount->currency,
            'email' => $this->payerEmail,
            'returnUrl' => $this->returnUrl,
            'invoice_url' => $this->invoiceUrl,
            'enableSplits' => $this->enableSplits,
            'store' => $this->store,
            'merchantOrgNr' => $this->merchantOrgNr,
            'merchantName' => $this->merchantName,
            'merchantCountry' => $this->merchantCountry,
            'merchantEmail' => $this->merchantEmail,
            'merchantPhone' => $this->merchantPhone,
        ]);
    }
}
