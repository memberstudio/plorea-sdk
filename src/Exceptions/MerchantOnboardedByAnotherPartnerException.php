<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Exceptions;

/**
 * Thrown when merchants()->onboard() is sent for a company that another
 * Plorea partner already onboarded. Plorea answers 409 and creates nothing.
 *
 * Captured live 2026-11-09: `{"error": "Merchant is onboarded by another
 * partner", "merchantOrgNr": "..."}`. The spec does not list it, and
 * merchants()->find() answers 404 for the same company. A payment link with
 * that org number was still created and its pay page opened; which balance
 * account receives the money has not been observed.
 */
class MerchantOnboardedByAnotherPartnerException extends RequestException
{
    /**
     * The organisation number Plorea refused.
     */
    public function merchantOrgNr(): ?string
    {
        $merchantOrgNr = $this->response?->json('merchantOrgNr');

        return is_string($merchantOrgNr) ? $merchantOrgNr : null;
    }
}
