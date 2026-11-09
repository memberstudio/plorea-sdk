<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Data;

use Carbon\CarbonImmutable;
use MemberFlow\Plorea\Data\Concerns\ParsesResponseData;

/**
 * A partner's merchant and how far its onboarding has come.
 *
 * Read from live on 2026-11-09 through GET /partners/merchants and
 * GET /partners/merchants/{merchantOrgNr}. A test key gets 400 on the list
 * and 404 on a single merchant: these routes are live only.
 *
 * The single read carries `tenantId` and `checkedAt`; a list item carries
 * `email` instead. `created` is only on the onboarding answer, which is
 * stated by Plorea and not captured.
 */
final readonly class PartnerMerchant
{
    use ParsesResponseData;

    /**
     * The step names, in the order Plorea runs them.
     */
    public const array STEPS = ['merchant', 'legalEntity', 'kyc', 'balanceAccount', 'store', 'cardMethods', 'partnerRow'];

    /**
     * @param  array<string, PartnerMerchantStep>  $steps  Keyed by step name; see STEPS.
     * @param  ?string  $kycUrl  Where the merchant verifies its company. Plorea e-mails the same link.
     * @param  ?bool  $created  True when the onboarding call created something. Null on a read.
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $merchantOrgNr,
        public ?string $tenantId,
        public ?string $name,
        public ?string $email,
        public ?string $kycUrl,
        public ?string $storeId,
        public array $steps,
        public ?CarbonImmutable $checkedAt,
        public ?bool $created = null,
        public array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $steps = is_array($data['steps'] ?? null) ? $data['steps'] : [];
        $parsed = [];

        foreach ($steps as $name => $step) {
            if (is_string($name) && is_array($step)) {
                $parsed[$name] = PartnerMerchantStep::fromArray($name, $step);
            }
        }

        return new self(
            merchantOrgNr: self::string($data['merchantOrgNr'] ?? null) ?? '',
            tenantId: self::string($data['tenantId'] ?? null),
            name: self::string($data['name'] ?? null),
            email: self::string($data['email'] ?? null),
            kycUrl: self::string($data['kycUrl'] ?? null),
            storeId: self::string($data['storeId'] ?? null),
            steps: $parsed,
            checkedAt: self::date($data['checkedAt'] ?? null),
            created: self::bool($data['created'] ?? null),
            raw: $data,
        );
    }

    /**
     * One onboarding step, or null when Plorea did not send it.
     */
    public function step(string $name): ?PartnerMerchantStep
    {
        return $this->steps[$name] ?? null;
    }

    /**
     * Whether the merchant can take card payments under its own store:
     * KYC `approved`, a store, and `ready` card methods.
     *
     * Payments for a merchant without a store did not go through in live in
     * November 2026: a subscription for such a merchant was never charged.
     */
    public function isReadyForPayments(): bool
    {
        return $this->step('kyc')?->is('approved') === true
            && $this->storeId !== null && $this->storeId !== ''
            && $this->step('cardMethods')?->is('ready') === true;
    }

    /**
     * Whether the merchant must act on its verification. See kycProblems().
     */
    public function needsKycAction(): bool
    {
        return $this->step('kyc')?->is('action_required') === true;
    }

    /**
     * Whether the KYC step was not read because the list request ran out of
     * time. Ask again; `deferred` is not a verification status.
     */
    public function isKycDeferred(): bool
    {
        return $this->step('kyc')?->is('deferred') === true;
    }

    /**
     * What the payment provider waits for, as Plorea lists it: `entityType`,
     * `entityId`, `code`, `type` and `message`. At most ten. The messages are
     * the provider's English text, for example "'bankAccount' was missing.".
     *
     * @return list<array<string, mixed>>
     */
    public function kycProblems(): array
    {
        $problems = $this->step('kyc')?->raw['problems'] ?? null;

        return is_array($problems) ? array_values(array_filter($problems, is_array(...))) : [];
    }
}
