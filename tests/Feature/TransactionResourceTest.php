<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use MemberFlow\Plorea\Data\Transaction;
use MemberFlow\Plorea\Exceptions\PloreaException;
use MemberFlow\Plorea\Exceptions\ValidationException;
use MemberFlow\Plorea\Facades\Plorea;
use MemberFlow\Plorea\Testing\RecordedRequest;
use MemberFlow\Plorea\Tests\TestCase;

class TransactionResourceTest extends TestCase
{
    /**
     * Captured 2026-11-09 in test, anonymised. The page holds a card setup,
     * a refunded payment link and a subscription-charge row whose status
     * (`created`) lagged behind the charge (`authorised`).
     */
    public function test_it_parses_a_real_transactions_page(): void
    {
        Http::fake([
            'payments.plorea.no/transactions*' => Http::response($this->fixture('transactions-page')),
        ]);

        $page = Plorea::transactions()->list(limit: 3);

        $this->assertSame('test-tenant', $page->tenantId);
        $this->assertSame(3, $page->count);
        $this->assertTrue($page->hasMore());
        $this->assertSame('dGVzdC1nb2xkZW4tY3Vyc29y', $page->nextCursor);
        $this->assertCount(3, $page->items);

        [$setup, $link, $charge] = $page->items->all();

        $this->assertTrue($setup->isCardSetup());
        $this->assertSame('unknown', $setup->platform);
        $this->assertSame(100, $setup->amount?->value);

        $this->assertSame('GOLDEN-2026-032', $link->reference);
        $this->assertTrue($link->is('refunded'));
        $this->assertSame(9950, $link->amount?->value);
        $this->assertSame('NOK', $link->amount?->currency);
        $this->assertSame(9950, $link->lastRefundAmount);
        $this->assertTrue($link->splitsEnabled);
        $this->assertSame('REFUND', $link->webhookEventCode);
        $this->assertFalse($link->isSubscriptionCharge());

        $this->assertTrue($charge->isSubscriptionCharge());
        $this->assertTrue($charge->is('created'));
        $this->assertNull($charge->pspReference);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), 'tenantId=test-tenant')
            && str_contains($request->url(), 'limit=3'));
    }

    public function test_it_sends_the_filters_and_an_explicit_tenant(): void
    {
        Plorea::fake();

        Plorea::transactions()->list(status: 'refunded', limit: 50, cursor: 'abc', tenantId: 'other-tenant');

        Plorea::assertSent(fn (RecordedRequest $request): bool => $request->matches('GET transactions')
            && $request->input('tenantId') === 'other-tenant'
            && $request->input('status') === 'refunded'
            && $request->input('limit') === 50
            && $request->input('cursor') === 'abc');
    }

    public function test_it_follows_the_cursor_through_every_page(): void
    {
        $page = $this->fixture('transactions-page');

        Plorea::fake([
            'transactions' => fn (RecordedRequest $request): array => $request->input('cursor') === null
                ? [...$page, 'items' => array_slice($page['items'], 0, 2), 'count' => 2, 'nextCursor' => 'page-2']
                : [...$page, 'items' => array_slice($page['items'], 2), 'count' => 1, 'nextCursor' => null],
        ]);

        $references = Plorea::transactions()->all()->map(fn (Transaction $row): string => $row->reference)->all();

        $this->assertSame(['pmsetup_pm_test_golden_1', 'GOLDEN-2026-032', 'sub_test_golden-chg_test_golden_33'], $references);
        Plorea::assertSentCount(2);
        Plorea::assertSent(fn (RecordedRequest $request): bool => $request->input('cursor') === 'page-2' && $request->input('limit') === 1000);
    }

    public function test_it_stops_when_plorea_repeats_a_cursor(): void
    {
        $page = $this->fixture('transactions-page');

        Plorea::fake([
            'transactions' => fn (): array => [...$page, 'nextCursor' => 'same-cursor'],
        ]);

        $this->expectException(PloreaException::class);

        Plorea::transactions()->all()->each(fn (): null => null);
    }

    /**
     * An app chooses its own link references, so a `sub_…-chg_…` or
     * `pmsetup_` reference on a pay.plorea.no link is still a payment.
     */
    public function test_an_app_reference_with_a_reserved_prefix_is_still_a_payment(): void
    {
        $link = Transaction::fromArray([
            'reference' => 'sub_order-chg_42',
            'paymentLinkId' => 'pl_fake',
            'paymentLinkUrl' => 'https://pay.plorea.no/pl_fake',
        ]);
        $setupLookalike = Transaction::fromArray([
            'reference' => 'pmsetup_invoice_42',
            'paymentLinkId' => 'pl_fake',
            'paymentLinkUrl' => 'https://pay.plorea.no/pl_fake',
        ]);

        $this->assertFalse($link->isSubscriptionCharge());
        $this->assertFalse($setupLookalike->isCardSetup());
    }

    public function test_it_refuses_a_limit_outside_what_plorea_accepts(): void
    {
        Plorea::fake();

        foreach ([0, 1001] as $limit) {
            try {
                Plorea::transactions()->list(limit: $limit);
                $this->fail("Expected limit {$limit} to be refused.");
            } catch (InvalidArgumentException) {
            }
        }

        Plorea::assertNothingSent();
    }

    /**
     * Captured 2026-11-09 in test.
     */
    public function test_a_foreign_cursor_is_a_validation_error(): void
    {
        Http::fake([
            'payments.plorea.no/transactions*' => Http::response(['error' => 'cursor is not one this endpoint handed out'], 400),
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('cursor is not one this endpoint handed out');

        Plorea::transactions()->list(cursor: 'made-up');
    }

    public function test_the_fake_answers_a_single_page(): void
    {
        Plorea::fake();

        $page = Plorea::transactions()->list();

        $this->assertFalse($page->hasMore());
        $this->assertSame('test-tenant', $page->tenantId);
        $this->assertTrue($page->items->first()?->is('authorised'));
    }
}
