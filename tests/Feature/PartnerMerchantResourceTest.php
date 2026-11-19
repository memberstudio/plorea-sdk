<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MemberFlow\Plorea\Exceptions\MerchantOnboardedByAnotherPartnerException;
use MemberFlow\Plorea\Exceptions\RequestException;
use MemberFlow\Plorea\Facades\Plorea;
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

        $page = Plorea::merchants()->list('test-tenant', 3);

        $this->assertSame('test-tenant', $page->tenantId);
        $this->assertSame(3, $page->count);
        $this->assertSame(0, $page->kycDeferred);
        $this->assertNotNull($page->nextCursor);
        $this->assertSame('2026-11-08T23:31:40+00:00', $page->checkedAt?->toIso8601String());

        [$ready, $waiting, $noStore] = $page->items->all();

        $this->assertTrue($ready->kyc->isApproved());
        $this->assertSame('ready', $ready->steps['cardMethods']['status']);
        $this->assertSame('ST_TEST_GOLDEN_1', $ready->storeId);
        $this->assertSame('TestMerchantAccount', $ready->steps['store']['merchantAccount']);

        $this->assertTrue($waiting->kyc->is('action_required'));
        $this->assertNull($waiting->storeId);
        $this->assertSame(['2_8067', '2_8069', '2_8036'], array_column($waiting->kyc->problems, 'code'));
        $this->assertSame("'bankAccount' was missing.", $waiting->kyc->problems[2]->message);

        $this->assertTrue($noStore->kyc->isApproved());
        $this->assertNull($noStore->storeId);
        $this->assertSame('no_store', $noStore->steps['cardMethods']['status']);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), 'tenantId=test-tenant')
            && str_contains($request->url(), 'limit=3'));
    }

    public function test_it_reads_one_merchant_for_the_configured_tenant(): void
    {
        Http::fake([
            'payments.plorea.no/partners/merchants/*' => Http::response($this->fixture('partner-merchant')),
        ]);

        $merchant = Plorea::merchants()->status('999999994');

        $this->assertSame('999999994', $merchant->merchantOrgNr);
        $this->assertSame('test-tenant', $merchant->tenantId);
        $this->assertTrue($merchant->kyc->isApproved());
        $this->assertSame('ready', $merchant->steps['cardMethods']['status']);
        $this->assertNull($merchant->created);
        $this->assertNotNull($merchant->checkedAt);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'partners/merchants/999999994?tenantId=test-tenant'));
    }

    /**
     * Captured 2026-11-09 in live, anonymised. Nothing was created.
     */
    public function test_a_company_another_partner_owns_is_a_409(): void
    {
        config(['plorea.environment' => 'live']);
        Http::fake([
            'payments.plorea.no/partners/merchants' => Http::response([
                'error' => 'Merchant is onboarded by another partner',
                'merchantOrgNr' => '999999995',
            ], 409),
        ]);

        try {
            Plorea::merchants()->onboarding('999999995', 'owner@example.com')->start();
            $this->fail('Expected the onboarding to be refused.');
        } catch (MerchantOnboardedByAnotherPartnerException $exception) {
            $this->assertInstanceOf(RequestException::class, $exception);
            $this->assertSame(409, $exception->status);
            $this->assertSame('Merchant is onboarded by another partner', $exception->getMessage());
            $this->assertSame('999999995', $exception->merchantOrgNr());
        }
    }
}
