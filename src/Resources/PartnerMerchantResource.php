<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Resources;

use Illuminate\Support\LazyCollection;
use InvalidArgumentException;
use MemberFlow\Plorea\Data\PartnerMerchant;
use MemberFlow\Plorea\Data\PartnerMerchantPage;
use MemberFlow\Plorea\Exceptions\PloreaException;
use MemberFlow\Plorea\Pending\PendingMerchantOnboarding;

/**
 * The partner's merchants and their onboarding: company, KYC, balance
 * account, store and card methods.
 *
 * Live only. A test key gets 400 "Partner merchants are live only"
 * (captured 2026-11-09). The reads are captured from live. A successful
 * onboard() is stated by Plorea and not yet seen.
 */
class PartnerMerchantResource extends Resource
{
    /**
     * The largest page Plorea accepts. 101 gets 400 (captured 2026-11-09).
     */
    public const int MAX_LIMIT = 100;

    /**
     * One merchant and its onboarding steps. A merchant that belongs to
     * another tenant, or to another Plorea partner, is a 404.
     */
    public function find(string $merchantOrgNr, ?string $tenantId = null): PartnerMerchant
    {
        return PartnerMerchant::fromArray($this->client->get(
            'partners/merchants/'.rawurlencode($merchantOrgNr),
            ['tenantId' => $tenantId ?? $this->tenantId()],
        ));
    }

    /**
     * One page of merchants, newest first. A cursor that this endpoint did
     * not hand out gets 400.
     */
    public function list(?int $limit = null, ?string $cursor = null, ?string $tenantId = null): PartnerMerchantPage
    {
        if ($limit !== null && ($limit < 1 || $limit > self::MAX_LIMIT)) {
            throw new InvalidArgumentException('The merchant limit must be between 1 and '.self::MAX_LIMIT.'.');
        }

        return PartnerMerchantPage::fromArray($this->client->get('partners/merchants', $this->withoutNulls([
            'tenantId' => $tenantId ?? $this->tenantId(),
            'limit' => $limit,
            'cursor' => $cursor,
        ])));
    }

    /**
     * Every merchant, one page at a time, following the cursor.
     *
     * @return LazyCollection<int, PartnerMerchant>
     *
     * @throws PloreaException When Plorea hands out a cursor a second time, which would loop forever.
     */
    public function all(int $pageSize = self::MAX_LIMIT, ?string $tenantId = null): LazyCollection
    {
        return LazyCollection::make(function () use ($pageSize, $tenantId) {
            $cursor = null;
            $seenCursors = [];

            do {
                $page = $this->list($pageSize, $cursor, $tenantId);

                yield from $page->items;

                $cursor = $page->nextCursor;

                if ($cursor === null || $cursor === '') {
                    return;
                }

                if (isset($seenCursors[$cursor])) {
                    throw new PloreaException('Plorea returned a merchants cursor it already handed out. Stopped to avoid an endless loop.');
                }

                $seenCursors[$cursor] = true;
            } while (true);
        })->values();
    }

    /**
     * Start onboarding a merchant, or read back one that exists.
     *
     * Plorea creates what is missing and e-mails `$email` the KYC link.
     * Calling it again for the same org number is safe: it answers 200 with
     * `created` false (stated by Plorea, not captured).
     *
     * A company that another Plorea partner already onboarded answers 409
     * "Merchant is onboarded by another partner" and nothing is created
     * (captured live 2026-11-09). It reaches you as a RequestException with
     * status 409. Payment links for that company are still accepted.
     *
     * ```php
     * $merchant = Plorea::merchants()
     *     ->onboard('999999999', 'billing@example.com')
     *     ->name('Acme Gym AS')
     *     ->cardDescriptor('ACME GYM')
     *     ->save();
     * ```
     */
    public function onboard(string $merchantOrgNr, string $email): PendingMerchantOnboarding
    {
        return new PendingMerchantOnboarding($this->client, $this->tenantId(), $merchantOrgNr, $email);
    }
}
