<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Resources;

use Illuminate\Support\LazyCollection;
use InvalidArgumentException;
use MemberFlow\Plorea\Data\Transaction;
use MemberFlow\Plorea\Data\TransactionPage;
use MemberFlow\Plorea\Exceptions\PloreaException;

/**
 * The tenant's payment rows, newest first: payment links, card setups and
 * refunds.
 *
 * Read in test on 2026-11-09. The API documents subscription charges as
 * absent, but rows named `{subscriptionId}-{chargeId}` do appear. Their
 * status can lag behind the charge: read a charge's state from the
 * subscription's charges, not from this list.
 */
class TransactionResource extends Resource
{
    /**
     * The largest page Plorea accepts (1–1000; a page of 705 was read in one call).
     */
    public const int MAX_LIMIT = 1000;

    /**
     * One page of transactions.
     *
     * A cursor that this endpoint did not hand out gets 400. Without a
     * tenant Plorea answers 404, so the configured tenant is sent when none
     * is given.
     *
     * @param  ?string  $status  Only rows with this status, for example `authorised`.
     */
    public function list(
        ?string $status = null,
        ?int $limit = null,
        ?string $cursor = null,
        ?string $tenantId = null,
    ): TransactionPage {
        if ($limit !== null && ($limit < 1 || $limit > self::MAX_LIMIT)) {
            throw new InvalidArgumentException('The transaction limit must be between 1 and '.self::MAX_LIMIT.'.');
        }

        return TransactionPage::fromArray($this->client->get('transactions', $this->withoutNulls([
            'tenantId' => $tenantId ?? $this->tenantId(),
            'status' => $status,
            'limit' => $limit,
            'cursor' => $cursor,
        ])));
    }

    /**
     * Every transaction, one page at a time, following the cursor.
     *
     * @return LazyCollection<int, Transaction>
     *
     * @throws PloreaException When Plorea hands out a cursor a second time, which would loop forever.
     */
    public function all(?string $status = null, int $pageSize = self::MAX_LIMIT, ?string $tenantId = null): LazyCollection
    {
        return LazyCollection::make(function () use ($status, $pageSize, $tenantId) {
            $cursor = null;
            $seenCursors = [];

            do {
                $page = $this->list($status, $pageSize, $cursor, $tenantId);

                yield from $page->items;

                $cursor = $page->nextCursor;

                if ($cursor === null || $cursor === '') {
                    return;
                }

                if (isset($seenCursors[$cursor])) {
                    throw new PloreaException('Plorea returned a transactions cursor it already handed out. Stopped to avoid an endless loop.');
                }

                $seenCursors[$cursor] = true;
            } while (true);
        })->values();
    }
}
