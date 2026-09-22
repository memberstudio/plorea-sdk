<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Tests\Feature;

use Carbon\CarbonImmutable;
use MemberFlow\Plorea\Data\Amount;
use MemberFlow\Plorea\Data\BillingInterval;
use MemberFlow\Plorea\Enums\RecurringType;
use MemberFlow\Plorea\Exceptions\ChargeFailedException;
use MemberFlow\Plorea\Exceptions\NotFoundException;
use MemberFlow\Plorea\Exceptions\PloreaException;
use MemberFlow\Plorea\Exceptions\ServerException;
use MemberFlow\Plorea\Facades\Plorea;
use MemberFlow\Plorea\Testing\RecordedRequest;
use MemberFlow\Plorea\Tests\TestCase;

class FakeClientTest extends TestCase
{
    public function test_it_returns_default_fixtures_without_stubs(): void
    {
        Plorea::fake();

        $link = Plorea::payments()
            ->link('ref-1', 'Product', Amount::nok(50000), 'https://example.test/return')
            ->merchant(orgNr: '999999999')
            ->create();

        $this->assertSame('pl_fake_link', $link->id);
        $this->assertSame('ref-1', $link->reference);
        $this->assertSame('test-tenant', $link->tenantId);

        $status = Plorea::payments()->status('ref-1');
        $this->assertTrue($status->isOpen());
        $this->assertSame(50000, $status->amount?->value);

        $method = Plorea::paymentMethods()->find('pm_1');
        $this->assertTrue($method->isActive());
        $this->assertSame('pm_1', $method->id);

        $subscription = Plorea::subscriptions()->find('sub_1');
        $this->assertSame('sub_1', $subscription->id);
        $this->assertTrue($subscription->isActive());

        $charge = Plorea::subscriptions()->charge('sub_1');
        $this->assertSame('chg_fake_charge', $charge->id);

        Plorea::assertSentCount(5);
    }

    /**
     * Pins the request count the testing docs quote for the fake's
     * firstOrCreate example. The walk always probes one suffix past the
     * reusable link, so this is create + status + pay page + status-1.
     */
    public function test_first_or_create_against_the_fake_costs_four_requests(): void
    {
        Plorea::fake();

        $pending = Plorea::payments()
            ->link('ref-1', 'Product', Amount::nok(50000), 'https://example.test/return')
            ->merchant(orgNr: '999999999');

        $pending->create();
        $again = $pending->firstOrCreate();

        $this->assertSame('ref-1', $again->reference);

        Plorea::assertSentCount(4);
    }

    public function test_status_for_an_unknown_reference_is_a_404(): void
    {
        Plorea::fake();

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('No payment found for reference [ref-unknown]');

        Plorea::payments()->status('ref-unknown');
    }

    public function test_first_or_create_works_without_stubs(): void
    {
        Plorea::fake();

        $created = Plorea::payments()
            ->link('ref-1', 'Product', Amount::nok(50000), 'https://example.test/return')
            ->merchant(orgNr: '999999999')
            ->firstOrCreate();

        $this->assertSame('pl_fake_link', $created->id);

        $reused = Plorea::payments()
            ->link('ref-1', 'Product', Amount::nok(50000), 'https://example.test/return')
            ->merchant(orgNr: '999999999')
            ->firstOrCreate();

        $this->assertSame('pl_fake_link', $reused->id);

        Plorea::assertSent('POST payments/link');
    }

    public function test_it_uses_stubbed_responses(): void
    {
        Plorea::fake([
            'payments/status/*' => ['reference' => 'ref-1', 'status' => 'failed'],
        ]);

        $status = Plorea::payments()->status('ref-1');

        $this->assertTrue($status->is('failed'));
    }

    public function test_it_supports_callable_and_throwable_stubs(): void
    {
        Plorea::fake([
            'POST payments/link' => fn (RecordedRequest $request): array => [
                'paymentLinkId' => 'pl_from_callable',
                'paymentLinkUrl' => 'https://pay.plorea.no/pl_from_callable',
                'reference' => $request->input('reference'),
                'status' => 'created',
            ],
            'subscriptions/*/charge' => new ChargeFailedException('Charge failed: Refused', 402),
        ]);

        $link = Plorea::payments()
            ->link('ref-9', 'Product', Amount::nok(1000), 'https://example.test')
            ->merchant(orgNr: '999999999')
            ->create();

        $this->assertSame('pl_from_callable', $link->id);
        $this->assertSame('ref-9', $link->reference);

        $this->expectException(ChargeFailedException::class);

        Plorea::subscriptions()->charge('sub_1');
    }

    public function test_it_records_requests_and_supports_assertions(): void
    {
        Plorea::fake();

        Plorea::paymentMethods()
            ->setup('shopper-1', RecurringType::Subscription, 'https://example.test/return')
            ->create();

        Plorea::assertSent('payment-methods/setup');
        Plorea::assertSent('POST payment-methods/setup');
        Plorea::assertSent(fn (RecordedRequest $request): bool => $request->input('shopperReference') === 'shopper-1');
        Plorea::assertNotSent('payments/link');
        Plorea::assertSentCount(1);

        $recorded = Plorea::recorded();
        $this->assertCount(1, $recorded);
        $this->assertSame('post', $recorded[0]->method);
        $this->assertSame('payment-methods/setup', $recorded[0]->path);
    }

    public function test_assert_nothing_sent(): void
    {
        Plorea::fake();

        Plorea::assertNothingSent();
    }

    public function test_unknown_paths_without_stub_raise_a_helpful_error(): void
    {
        $fake = Plorea::fake();

        $this->expectException(PloreaException::class);
        $this->expectExceptionMessage('No fake response registered');

        $fake->get('some/unknown/endpoint');
    }

    public function test_a_subscription_created_with_a_future_trial_is_reported_as_trialing(): void
    {
        Plorea::fake();

        $subscription = Plorea::subscriptions()
            ->create('pm_1', Amount::nok(19900), BillingInterval::monthly())
            ->trialUntil(CarbonImmutable::now()->addDays(14))
            ->save();

        $this->assertTrue($subscription->isTrialing());
        $this->assertFalse($subscription->isActive());
    }

    public function test_the_fake_echoes_only_the_merchant_organisation_number(): void
    {
        Plorea::fake();

        $subscription = Plorea::subscriptions()
            ->create('pm_1', Amount::nok(19900), BillingInterval::monthly())
            ->externalId('ws_1')
            ->merchant(orgNr: '999999999', name: 'Acme Gym AS', email: 'billing@example.com')
            ->save();

        $this->assertSame('999999999', $subscription->merchantOrgNr);
        $this->assertArrayNotHasKey('merchantName', $subscription->raw);
        $this->assertArrayNotHasKey('merchantEmail', $subscription->raw);

        // Reads return the organisation number too, as the API does.
        $found = Plorea::subscriptions()->find($subscription->id);

        $this->assertSame('999999999', $found->merchantOrgNr);
        $this->assertArrayNotHasKey('merchantName', $found->raw);
        $this->assertSame('999999999', Plorea::subscriptions()->forExternalId('ws_1')->first()?->merchantOrgNr);

        // Nothing was created under another id or external id.
        $this->assertNull(Plorea::subscriptions()->find('sub_other')->merchantOrgNr);
        $this->assertNull(Plorea::subscriptions()->forExternalId('ws_2')->first()?->merchantOrgNr);
    }

    public function test_a_subscription_created_with_a_past_trial_is_reported_as_active(): void
    {
        Plorea::fake();

        $subscription = Plorea::subscriptions()
            ->create('pm_1', Amount::nok(19900), BillingInterval::monthly())
            ->trialUntil(CarbonImmutable::now()->subDay())
            ->save();

        $this->assertTrue($subscription->isActive());
    }

    public function test_the_list_applies_the_tenant_and_status_filters(): void
    {
        Plorea::fake();

        $subscriptions = Plorea::subscriptions()->forExternalId('ws_1', tenantId: 'other-tenant', status: 'canceled');

        $this->assertCount(1, $subscriptions);
        $this->assertSame('other-tenant', $subscriptions->first()?->tenantId);
        $this->assertTrue($subscriptions->first()?->isCanceled());
    }

    public function test_a_manual_charge_echoes_the_requested_amount_and_vat(): void
    {
        Plorea::fake();

        $charge = Plorea::subscriptions()->charge('sub_1', Amount::nok(45000), vatRate: 0.25, vatAmount: 9000);

        $this->assertSame(45000, $charge->amount?->value);
        $this->assertSame(0.25, $charge->vatRate);
        $this->assertSame(9000, $charge->vatAmount);
    }

    /**
     * The fake reports a status only for references it has seen a link
     * created for. A creation whose stub threw never created anything, so it
     * must not make the reference findable — otherwise a consumer's
     * failed-creation recovery test passes against a fake that succeeded.
     */
    public function test_a_creation_that_failed_does_not_make_the_reference_findable(): void
    {
        $fake = Plorea::fake([
            'POST payments/link' => new ServerException('Plorea is down', 500),
        ]);

        try {
            Plorea::payments()
                ->link('ref-failed', 'Product', Amount::nok(50000), 'https://example.test/return')
                ->merchant(orgNr: '999999999')
                ->create();
            $this->fail('Expected ServerException.');
        } catch (ServerException) {
            // Expected — the link was never created.
        }

        $fake->assertSent('POST payments/link');

        $this->expectException(NotFoundException::class);

        Plorea::payments()->status('ref-failed');
    }

    public function test_assertions_require_the_fake(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('has not been faked');

        Plorea::assertNothingSent();
    }
}
