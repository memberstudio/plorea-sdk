<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MemberFlow\Plorea\Data\Amount;
use MemberFlow\Plorea\Data\BillingInterval;
use MemberFlow\Plorea\Data\Subscription;
use MemberFlow\Plorea\Exceptions\ChargeFailedException;
use MemberFlow\Plorea\Exceptions\PloreaException;
use MemberFlow\Plorea\Facades\Plorea;
use MemberFlow\Plorea\Tests\TestCase;

class SubscriptionResourceTest extends TestCase
{
    public function test_it_creates_a_subscription(): void
    {
        Http::fake([
            'payments.plorea.no/subscriptions' => Http::response([
                'subscriptionId' => 'sub_1',
                'tenantId' => 'test-tenant',
                'paymentMethodId' => 'pm_1',
                'recurringType' => 'Subscription',
                'amount' => ['value' => 19900, 'currency' => 'NOK'],
                'interval' => ['unit' => 'month', 'count' => 1],
                'externalId' => 'ws_acme_456',
                'trialEndsAt' => '2026-09-01T00:00:00Z',
                'status' => 'trialing',
            ], 201),
        ]);

        $subscription = Plorea::subscriptions()
            ->create('pm_1', Amount::nok(19900), BillingInterval::monthly())
            ->externalId('ws_acme_456')
            ->title('Done CRM Pro')
            ->trialUntil(new \DateTimeImmutable('2026-09-01T00:00:00+00:00'))
            ->retryPolicy(3, 2)
            ->vat(rate: 0.25, amount: 3980)
            ->save();

        $this->assertSame('sub_1', $subscription->id);
        $this->assertTrue($subscription->isTrialing());
        $this->assertSame(19900, $subscription->amount?->value);
        $this->assertSame('month', $subscription->interval?->unit->value);
        $this->assertSame('ws_acme_456', $subscription->externalId);

        Http::assertSent(fn (Request $request): bool => $request->data() === [
            'tenantId' => 'test-tenant',
            'paymentMethodId' => 'pm_1',
            'recurringType' => 'Subscription',
            'amount' => ['value' => 19900, 'currency' => 'NOK'],
            'interval' => ['unit' => 'month', 'count' => 1],
            'trialEndsAt' => '2026-09-01T00:00:00+00:00',
            'retryPolicy' => ['maxRetries' => 3, 'retryIntervalDays' => 2],
            'externalId' => 'ws_acme_456',
            'title' => 'Done CRM Pro',
            'vatRate' => 0.25,
            'vatAmount' => 3980,
        ]);
    }

    public function test_it_updates_a_subscription(): void
    {
        Http::fake([
            'payments.plorea.no/subscriptions/sub_1' => Http::response([
                'subscriptionId' => 'sub_1',
                'amount' => ['value' => 39900, 'currency' => 'NOK'],
                'quantity' => 10,
                'status' => 'active',
            ]),
        ]);

        $subscription = Plorea::subscriptions()->update('sub_1')
            ->amount(Amount::nok(39900))
            ->quantity(10)
            ->save();

        $this->assertSame(39900, $subscription->amount?->value);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && $request->url() === 'https://payments.plorea.no/subscriptions/sub_1'
            && $request->data() === [
                'amount' => ['value' => 39900, 'currency' => 'NOK'],
                'quantity' => 10,
            ]);
    }

    public function test_an_empty_update_is_rejected_before_sending(): void
    {
        Http::fake();

        $this->expectException(PloreaException::class);
        $this->expectExceptionMessage('at least one field');

        Plorea::subscriptions()->update('sub_1')->save();
    }

    public function test_it_lists_subscriptions_by_external_id(): void
    {
        Http::fake([
            'payments.plorea.no/subscriptions?*' => Http::response([
                'externalId' => 'ws_acme_456',
                'count' => 2,
                'items' => [
                    ['subscriptionId' => 'sub_1', 'status' => 'active'],
                    ['subscriptionId' => 'sub_2', 'status' => 'canceled'],
                ],
            ]),
        ]);

        $subscriptions = Plorea::subscriptions()->forExternalId('ws_acme_456', status: 'active');

        $this->assertCount(2, $subscriptions);
        $this->assertContainsOnlyInstancesOf(Subscription::class, $subscriptions);
        $this->assertSame('sub_1', $subscriptions[0]->id);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://payments.plorea.no/subscriptions?externalId=ws_acme_456&status=active');
    }

    public function test_it_charges_a_subscription(): void
    {
        Http::fake([
            'payments.plorea.no/subscriptions/sub_1/charge' => Http::response([
                'status' => 'charge_created',
                'subscriptionId' => 'sub_1',
                'chargeId' => 'chg_1',
                'reference' => 'sub_1-chg_1',
                'pspReference' => 'PSP123',
                'resultCode' => 'Authorised',
                'amount' => ['value' => 19900, 'currency' => 'NOK'],
                'nextChargeAt' => '2026-10-01T00:00:00Z',
            ], 201),
        ]);

        $charge = Plorea::subscriptions()->charge('sub_1', reason: 'extra_seat');

        $this->assertSame('chg_1', $charge->id);
        $this->assertSame('Authorised', $charge->resultCode);
        $this->assertSame('2026-10-01', $charge->nextChargeAt?->toDateString());

        Http::assertSent(fn (Request $request): bool => $request->data() === ['reason' => 'extra_seat']);
    }

    public function test_a_declined_charge_throws_a_charge_failed_exception(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'Charge failed: Refused'], 402),
        ]);

        $this->expectException(ChargeFailedException::class);
        $this->expectExceptionMessage('Charge failed: Refused');

        Plorea::subscriptions()->charge('sub_1');
    }

    public function test_it_lists_charges(): void
    {
        Http::fake([
            'payments.plorea.no/subscriptions/sub_1/charges' => Http::response([
                'subscriptionId' => 'sub_1',
                'items' => [
                    [
                        'chargeId' => 'chg_2',
                        'subscriptionId' => 'sub_1',
                        'amount' => ['value' => 19900, 'currency' => 'NOK'],
                        'status' => 'authorised',
                        'retryNumber' => 0,
                    ],
                    [
                        'chargeId' => 'chg_1',
                        'subscriptionId' => 'sub_1',
                        'amount' => ['value' => 19900, 'currency' => 'NOK'],
                        'status' => 'failed',
                        'failureReason' => 'Refused',
                        'retryNumber' => 1,
                    ],
                ],
            ]),
        ]);

        $charges = Plorea::subscriptions()->charges('sub_1');

        $this->assertCount(2, $charges);
        $this->assertSame('chg_2', $charges[0]->id);
        $this->assertSame('Refused', $charges[1]->failureReason);
    }

    public function test_it_cancels_and_reactivates_a_subscription(): void
    {
        Http::fake([
            'payments.plorea.no/subscriptions/sub_1/cancel' => Http::response([
                'subscriptionId' => 'sub_1',
                'status' => 'canceled',
                'canceledAt' => '2026-08-26T12:00:00Z',
                'reason' => 'customer_requested',
            ]),
            'payments.plorea.no/subscriptions/sub_1/reactivate' => Http::response([
                'subscriptionId' => 'sub_1',
                'status' => 'active',
            ]),
        ]);

        $cancellation = Plorea::subscriptions()->cancel('sub_1', 'customer_requested');

        $this->assertSame('canceled', $cancellation->status);
        $this->assertSame('2026-08-26', $cancellation->canceledAt?->toDateString());

        $subscription = Plorea::subscriptions()->reactivate('sub_1');

        $this->assertTrue($subscription->isActive());

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/cancel')
            && $request->data() === ['reason' => 'customer_requested']);
    }
}
