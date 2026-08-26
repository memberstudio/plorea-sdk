<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Pending;

use MemberFlow\Plorea\Concerns\FiltersNullValues;
use MemberFlow\Plorea\Contracts\Client;
use MemberFlow\Plorea\Data\PaymentMethod;
use MemberFlow\Plorea\Data\PaymentMethodSession;
use MemberFlow\Plorea\Enums\RecurringType;

/**
 * Fluently configures a payment method setup.
 */
class PendingPaymentMethodSetup
{
    use FiltersNullValues;

    protected ?string $customerId = null;

    protected ?string $doneId = null;

    protected ?string $description = null;

    /** @var array<string, mixed> */
    protected array $metadata = [];

    public function __construct(
        protected readonly Client $client,
        protected string $tenantId,
        protected readonly string $shopperReference,
        protected readonly RecurringType $recurringType,
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
     * Your own customer identifier.
     */
    public function customerId(string $customerId): static
    {
        $this->customerId = $customerId;

        return $this;
    }

    /**
     * The universal Done user identifier. Only used by the Drop-in session flow.
     */
    public function doneId(string $doneId): static
    {
        $this->doneId = $doneId;

        return $this;
    }

    /**
     * A description shown to the customer. Only used by the hosted flow.
     */
    public function description(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function metadata(array $metadata): static
    {
        $this->metadata = $metadata;

        return $this;
    }

    /**
     * Create a hosted Adyen setup page. Redirect the customer to
     * the returned `adyenPaymentLinkUrl`.
     */
    public function create(): PaymentMethod
    {
        return PaymentMethod::fromArray(
            $this->client->post('payment-methods/setup', $this->toPayload(includeDescription: true)),
        );
    }

    /**
     * Create an Adyen Sessions object for the embedded Web Drop-in component.
     */
    public function session(): PaymentMethodSession
    {
        return PaymentMethodSession::fromArray(
            $this->client->post('payment-methods/setup/session', $this->toPayload(includeDoneId: true)),
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function toPayload(bool $includeDescription = false, bool $includeDoneId = false): array
    {
        return $this->withoutNulls([
            'tenantId' => $this->tenantId,
            'customerId' => $this->customerId,
            'doneId' => $includeDoneId ? $this->doneId : null,
            'shopperReference' => $this->shopperReference,
            'recurringType' => $this->recurringType->value,
            'returnUrl' => $this->returnUrl,
            'description' => $includeDescription ? $this->description : null,
            'metadata' => $this->metadata === [] ? null : $this->metadata,
        ]);
    }
}
