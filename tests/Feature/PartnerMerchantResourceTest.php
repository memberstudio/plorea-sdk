<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use MemberFlow\Plorea\Data\PartnerMerchant;
use MemberFlow\Plorea\Facades\Plorea;
use MemberFlow\Plorea\Testing\RecordedRequest;
use MemberFlow\Plorea\Tests\TestCase;

class PartnerMerchantResourceTest extends TestCase
{
    /**
     * Captured 2026-11-09 in live, anonymised. One merchant is ready, one
     * waits for KYC and has no store, one is approved but still has no store.
     */
    public function test_it_parses_a_real_merchant_page(): void
    {
        Http::fake([
            'payments.plorea.no/partners/merchants*' => Http::response($this->fixture('partner-merchants-page')),
        ]);

        $page = Plorea::merchants()->list(limit: 3);

        $this->assertSame('test-tenant', $page->tenantId);
        $this->assertSame(3, $page->count);
        $this->assertSame(0, $page->kycDeferred);
        $this->assertTrue($page->hasMore());
        $this->assertSame('2026-11-08T23:31:40+00:00', $page->checkedAt?->toIso8601String());

        [$ready, $waiting, $noStore] = $page->items->all();

        $this->assertTrue($ready->isReadyForPayments());
        $this->assertFalse($ready->needsKycAction());
        $this->assertSame('ST_TEST_GOLDEN_1', $ready->storeId);
        $this->assertSame('TestMerchantAccount', $ready->step('store')?->raw['merchantAccount']);
        $this->assertSame(array_combine(PartnerMerchant::STEPS, PartnerMerchant::STEPS), array_combine(array_keys($ready->steps), array_keys($ready->steps)));

        $this->assertFalse($waiting->isReadyForPayments());
        $this->assertTrue($waiting->needsKycAction());
        $this->assertNull($waiting->storeId);
        $this->assertSame(['2_8067', '2_8069', '2_8036'], array_column($waiting->kycProblems(), 'code'));
        $this->assertSame("'bankAccount' was missing.", $waiting->kycProblems()[2]['message']);

        $this->assertFalse($noStore->isReadyForPayments());
        $this->assertTrue($noStore->step('kyc')?->is('approved'));
        $this->assertTrue($noStore->step('cardMethods')?->is('no_store'));
        $this->assertFalse($noStore->isKycDeferred());

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), 'tenantId=test-tenant')
            && str_contains($request->url(), 'limit=3'));
    }

    public function test_it_reads_one_merchant_for_the_configured_tenant(): void
    {
        Http::fake([
            'payments.plorea.no/partners/merchants/*' => Http::response($this->fixture('partner-merchant')),
        ]);

        $merchant = Plorea::merchants()->find('999999994');

        $this->assertSame('999999994', $merchant->merchantOrgNr);
        $this->assertSame('test-tenant', $merchant->tenantId);
        $this->assertTrue($merchant->isReadyForPayments());
        $this->assertNull($merchant->created);
        $this->assertNotNull($merchant->checkedAt);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'partners/merchants/999999994?tenantId=test-tenant'));
    }

    public function test_it_onboards_a_merchant_with_the_full_payload(): void
    {
        Plorea::fake();

        $merchant = Plorea::merchants()->onboard('999999995', 'owner@example.com')
            ->tenant('other-tenant')
            ->name('Golden Merchant 5 AS')
            ->phone('+4799999999')
            ->country('no')
            ->cardDescriptor('GOLDEN MERCHANT 5')
            ->save();

        $this->assertTrue($merchant->created);
        $this->assertSame('999999995', $merchant->merchantOrgNr);
        $this->assertSame('Golden Merchant 5 AS', $merchant->name);

        Plorea::assertSent(fn (RecordedRequest $request): bool => $request->matches('POST partners/merchants')
            && $request->data === [
                'tenantId' => 'other-tenant',
                'merchantOrgNr' => '999999995',
                'email' => 'owner@example.com',
                'name' => 'Golden Merchant 5 AS',
                'phone' => '+4799999999',
                'country' => 'NO',
                'cardDescriptor' => 'GOLDEN MERCHANT 5',
                'platform' => 'memberflow',
            ]);
    }

    public function test_onboarding_sends_only_what_was_set(): void
    {
        $payload = Plorea::merchants()->onboard('999999995', 'owner@example.com')->toPayload();

        $this->assertSame(['tenantId' => 'test-tenant', 'merchantOrgNr' => '999999995', 'email' => 'owner@example.com'], $payload);
    }

    public function test_it_refuses_a_card_descriptor_plorea_would_reject(): void
    {
        foreach (['GOLDEN MERCHANT NUMBER 5', 'GOLDEN & SONS', '', str_repeat('A', 22)."\n"] as $descriptor) {
            try {
                Plorea::merchants()->onboard('999999995', 'owner@example.com')->cardDescriptor($descriptor);
                $this->fail("Expected descriptor [{$descriptor}] to be refused.");
            } catch (InvalidArgumentException) {
            }
        }

        $this->assertSame('GOLDEN-SONS 5', Plorea::merchants()->onboard('999999995', 'owner@example.com')
            ->cardDescriptor('GOLDEN-SONS 5')->toPayload()['cardDescriptor']);
    }

    public function test_it_refuses_a_limit_outside_what_plorea_accepts(): void
    {
        Plorea::fake();

        foreach ([0, 101] as $limit) {
            try {
                Plorea::merchants()->list(limit: $limit);
                $this->fail("Expected limit {$limit} to be refused.");
            } catch (InvalidArgumentException) {
            }
        }

        Plorea::assertNothingSent();
    }

    public function test_it_follows_the_cursor_through_every_page(): void
    {
        $page = $this->fixture('partner-merchants-page');

        Plorea::fake([
            'partners/merchants' => fn (RecordedRequest $request): array => $request->input('cursor') === null
                ? [...$page, 'items' => array_slice($page['items'], 0, 1), 'count' => 1, 'nextCursor' => 'page-2']
                : [...$page, 'items' => array_slice($page['items'], 1), 'count' => 2, 'nextCursor' => null],
        ]);

        $orgNumbers = Plorea::merchants()->all()->map(fn (PartnerMerchant $merchant): string => $merchant->merchantOrgNr)->all();

        $this->assertSame(['999999991', '999999992', '999999993'], $orgNumbers);
        Plorea::assertSentCount(2);
        Plorea::assertSent(fn (RecordedRequest $request): bool => $request->input('cursor') === 'page-2' && $request->input('limit') === 100);
    }

    public function test_the_fake_answers_reads(): void
    {
        Plorea::fake();

        $this->assertTrue(Plorea::merchants()->find('999999996')->isReadyForPayments());
        $this->assertFalse(Plorea::merchants()->list()->hasMore());
    }
}
