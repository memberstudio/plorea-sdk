<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Tests\Feature;

use MemberFlow\Plorea\Data\Amount;
use MemberFlow\Plorea\Enums\RecurringType;
use MemberFlow\Plorea\Exceptions\ChargeFailedException;
use MemberFlow\Plorea\Exceptions\PloreaException;
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
            ->create();

        $this->assertSame('pl_fake_link', $link->id);
        $this->assertSame('ref-1', $link->reference);
        $this->assertSame('test-tenant', $link->tenantId);

        $status = Plorea::payments()->status('ref-1');
        $this->assertTrue($status->isAuthorised());

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

    public function test_it_uses_stubbed_responses(): void
    {
        Plorea::fake([
            'payments/status/*' => ['reference' => 'ref-1', 'status' => 'refused'],
        ]);

        $status = Plorea::payments()->status('ref-1');

        $this->assertTrue($status->is('refused'));
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

    public function test_assertions_require_the_fake(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('has not been faked');

        Plorea::assertNothingSent();
    }
}
