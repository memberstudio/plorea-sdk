<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Resources;

use MemberFlow\Plorea\Data\Merchant;
use MemberFlow\Plorea\Data\MerchantPage;
use MemberFlow\Plorea\Pending\PendingMerchantOnboarding;

/**
 * Live-only partner merchants. Reads never start or repair onboarding.
 */
class MerchantResource extends Resource
{
    /**
     * Read one page, newest first. Pass nextCursor unchanged for the next page.
     * A shared platform key requires tenantId; a tenant key can omit it.
     */
    public function list(?string $tenantId = null, int $limit = 25, ?string $cursor = null): MerchantPage
    {
        return MerchantPage::fromArray($this->client->get('partners/merchants', $this->withoutNulls([
            'tenantId' => $tenantId ?? $this->configuredTenantId(),
            'limit' => $limit,
            'cursor' => $cursor,
        ])));
    }

    /**
     * Read current onboarding steps. A 404 can mean unknown, another partner's
     * merchant, a tenant mismatch, or a test key — never initiate on that basis.
     */
    public function status(string $merchantOrgNr, ?string $tenantId = null): Merchant
    {
        return Merchant::fromArray($this->client->get(
            'partners/merchants/'.rawurlencode($merchantOrgNr),
            $this->withoutNulls(['tenantId' => $tenantId ?? $this->configuredTenantId()]),
        ));
    }

    /**
     * Prepare onboarding without sending anything. Only start() sends the POST:
     * it can start real verification, email the merchant and order card methods.
     */
    public function onboarding(string $merchantOrgNr, string $email): PendingMerchantOnboarding
    {
        return new PendingMerchantOnboarding(
            $this->client,
            $this->configuredTenantId(),
            $merchantOrgNr,
            $email,
            $this->environment(),
        );
    }

    protected function configuredTenantId(): ?string
    {
        $tenantId = $this->config['tenant_id'] ?? null;

        return is_string($tenantId) && $tenantId !== '' ? $tenantId : null;
    }
}
