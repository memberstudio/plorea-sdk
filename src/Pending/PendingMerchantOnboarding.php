<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Pending;

use InvalidArgumentException;
use MemberFlow\Plorea\Concerns\FiltersNullValues;
use MemberFlow\Plorea\Contracts\Client;
use MemberFlow\Plorea\Data\PartnerMerchant;

/**
 * Fluently configures a partner merchant onboarding.
 */
class PendingMerchantOnboarding
{
    use FiltersNullValues;

    /**
     * What card schemes print on the cardholder's statement: at most 22
     * characters from this set (API spec, 0.31).
     */
    public const string CARD_DESCRIPTOR_PATTERN = "/^[a-zA-Z0-9 .,'_\\-?+*]{1,22}$/D";

    protected ?string $name = null;

    protected ?string $phone = null;

    protected ?string $country = null;

    protected ?string $cardDescriptor = null;

    public function __construct(
        protected readonly Client $client,
        protected string $tenantId,
        protected readonly string $merchantOrgNr,
        protected readonly string $email,
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
     * The company name. Plorea looks it up from the org number when omitted.
     */
    public function name(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    /**
     * The merchant's phone number.
     */
    public function phone(string $phone): static
    {
        $this->phone = $phone;

        return $this;
    }

    /**
     * The merchant's country as an ISO 3166-1 alpha-2 code, for example `NO`.
     */
    public function country(string $country): static
    {
        $this->country = strtoupper($country);

        return $this;
    }

    /**
     * The text on the cardholder's statement.
     *
     * @throws InvalidArgumentException When it is longer than 22 characters or uses other characters than a-z, A-Z, 0-9, space and .,'_-?+*
     */
    public function cardDescriptor(string $cardDescriptor): static
    {
        if (preg_match(self::CARD_DESCRIPTOR_PATTERN, $cardDescriptor) !== 1) {
            throw new InvalidArgumentException(
                "The card descriptor must be 1-22 characters from a-z, A-Z, 0-9, space and .,'_-?+*.",
            );
        }

        $this->cardDescriptor = $cardDescriptor;

        return $this;
    }

    /**
     * The request payload.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return $this->withoutNulls([
            'tenantId' => $this->tenantId,
            'merchantOrgNr' => $this->merchantOrgNr,
            'email' => $this->email,
            'name' => $this->name,
            'phone' => $this->phone,
            'country' => $this->country,
            'cardDescriptor' => $this->cardDescriptor,
        ]);
    }

    /**
     * Send the onboarding to Plorea. Live only: a test key gets 400.
     */
    public function save(): PartnerMerchant
    {
        return PartnerMerchant::fromArray($this->client->post('partners/merchants', $this->toPayload()));
    }
}
