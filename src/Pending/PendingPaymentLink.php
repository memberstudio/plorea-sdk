<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Pending;

use MemberFlow\Plorea\Contracts\Client;
use MemberFlow\Plorea\Data\Amount;
use MemberFlow\Plorea\Data\PaymentLinkCreated;

/**
 * Fluently configures and creates a payment link.
 */
class PendingPaymentLink
{
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
        protected readonly string $reference,
        protected readonly string $product,
        protected readonly Amount $amount,
        protected readonly string $returnUrl,
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
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return array_filter([
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
        ], fn (mixed $value): bool => $value !== null);
    }
}
