<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Pending;

use MemberFlow\Plorea\Concerns\FiltersNullValues;
use MemberFlow\Plorea\Contracts\Client;
use MemberFlow\Plorea\Data\Merchant;
use MemberFlow\Plorea\Exceptions\PloreaException;

class PendingMerchantOnboarding
{
    use FiltersNullValues;

    protected ?string $name = null;

    protected ?string $phone = null;

    protected ?string $country = null;

    protected ?string $cardDescriptor = null;

    public function __construct(
        protected readonly Client $client,
        protected ?string $tenantId,
        protected readonly string $merchantOrgNr,
        protected readonly string $email,
        protected readonly ?string $environment = null,
    ) {}

    public function tenant(string $tenantId): static
    {
        $this->tenantId = $tenantId;

        return $this;
    }

    public function name(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function phone(string $phone): static
    {
        $this->phone = $phone;

        return $this;
    }

    public function country(string $country): static
    {
        $this->country = $country;

        return $this;
    }

    public function cardDescriptor(string $cardDescriptor): static
    {
        $this->cardDescriptor = $cardDescriptor;

        return $this;
    }

    /**
     * Start REAL onboarding: verification, email, payouts, store and card methods.
     * A repeat can order missing card methods; it does not update contact details.
     * Use merchants()->status() for read-only progress checks.
     *
     * @throws PloreaException Unless the SDK is explicitly configured for live.
     */
    public function start(): Merchant
    {
        if ($this->environment !== 'live') {
            throw new PloreaException(
                'Merchant onboarding starts real verification and sends email. '
                .'It requires PLOREA_ENVIRONMENT=live and a matching live key. '
                .'Use merchants()->status() to read progress without starting onboarding.',
            );
        }

        return Merchant::fromArray($this->client->post('partners/merchants', $this->toPayload()));
    }

    /**
     * Inspect the payload without starting onboarding.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return $this->withoutNulls([
            'merchantOrgNr' => $this->merchantOrgNr,
            'email' => $this->email,
            'tenantId' => $this->tenantId,
            'name' => $this->name,
            'phone' => $this->phone,
            'country' => $this->country,
            'cardDescriptor' => $this->cardDescriptor,
        ]);
    }
}
