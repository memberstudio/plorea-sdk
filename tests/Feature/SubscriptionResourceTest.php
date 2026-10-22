<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Tests\Feature;

use DateTimeImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MemberFlow\Plorea\Data\Amount;
use MemberFlow\Plorea\Data\BillingInterval;
use MemberFlow\Plorea\Data\Subscription;
use MemberFlow\Plorea\Exceptions\ChargeFailedException;
use MemberFlow\Plorea\Exceptions\NextChargeInPaidPeriodException;
use MemberFlow\Plorea\Exceptions\PloreaException;
use MemberFlow\Plorea\Exceptions\RequestException;
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
            ->trialUntil(new DateTimeImmutable('2026-09-01T02:00:00+02:00'))
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
            'trialEndsAt' => '2026-09-01T00:00:00.000Z',
            'retryPolicy' => ['maxRetries' => 3, 'retryIntervalDays' => 2],
            'externalId' => 'ws_acme_456',
            'title' => 'Done CRM Pro',
            'vatRate' => 0.25,
            'vatAmount' => 3980,
            'platform' => 'memberflow',
        ]);
    }

    public function test_it_maps_the_environment_from_a_read(): void
    {
        // Stated by Plorea on 2026-10-13: GET subscription responses carry the
        // environment of the API key. Older responses have no such field.
        Http::fake([
            'payments.plorea.no/subscriptions/sub_live*' => Http::response([
                'subscriptionId' => 'sub_live',
                'status' => 'active',
                'environment' => 'live',
            ]),
            'payments.plorea.no/subscriptions/sub_old*' => Http::response([
                'subscriptionId' => 'sub_old',
                'status' => 'active',
            ]),
        ]);

        $this->assertSame('live', Plorea::subscriptions()->find('sub_live')->environment);
        $this->assertNull(Plorea::subscriptions()->find('sub_old')->environment);
    }

    public function test_it_maps_the_current_period_end(): void
    {
        // Verified in test on 2026-10-14: present on active, charged
        // subscriptions, where it equals nextChargeAt; absent on trialing ones.
        Http::fake([
            'payments.plorea.no/subscriptions/sub_charged*' => Http::response([
                'subscriptionId' => 'sub_charged',
                'status' => 'active',
                'nextChargeAt' => '2026-11-14T08:00:00.000Z',
                'currentPeriodEnd' => '2026-11-14T08:00:00.000Z',
            ]),
            'payments.plorea.no/subscriptions/sub_trial*' => Http::response([
                'subscriptionId' => 'sub_trial',
                'status' => 'trialing',
                'trialEndsAt' => '2026-11-01T00:00:00.000Z',
            ]),
        ]);

        $charged = Plorea::subscriptions()->find('sub_charged');

        $this->assertEquals($charged->nextChargeAt, $charged->currentPeriodEnd);
        $this->assertNull(Plorea::subscriptions()->find('sub_trial')->currentPeriodEnd);
    }

    public function test_it_sends_the_merchant_when_creating_a_subscription(): void
    {
        Http::fake([
            'payments.plorea.no/subscriptions' => Http::response([
                'subscriptionId' => 'sub_1',
                'status' => 'active',
                'merchantOrgNr' => '999999999',
            ], 201),
        ]);

        $subscription = Plorea::subscriptions()
            ->create('pm_1', Amount::nok(19900), BillingInterval::monthly())
            ->merchant(orgNr: '999999999', name: 'Acme Gym AS', email: 'billing@example.com')
            ->save();

        $this->assertSame('999999999', $subscription->merchantOrgNr);

        Http::assertSent(fn (Request $request): bool => $request->data() === [
            'tenantId' => 'test-tenant',
            'paymentMethodId' => 'pm_1',
            'recurringType' => 'Subscription',
            'amount' => ['value' => 19900, 'currency' => 'NOK'],
            'interval' => ['unit' => 'month', 'count' => 1],
            'merchantOrgNr' => '999999999',
            'merchantName' => 'Acme Gym AS',
            'merchantEmail' => 'billing@example.com',
            'platform' => 'memberflow',
        ]);
    }

    public function test_a_subscription_without_a_merchant_sends_no_merchant_fields(): void
    {
        Http::fake([
            'payments.plorea.no/subscriptions' => Http::response(['subscriptionId' => 'sub_1', 'status' => 'active'], 201),
        ]);

        $subscription = Plorea::subscriptions()
            ->create('pm_1', Amount::nok(19900), BillingInterval::monthly())
            ->save();

        $this->assertNull($subscription->merchantOrgNr);

        Http::assertSent(fn (Request $request): bool => ! array_key_exists('merchantOrgNr', $request->data())
            && ! array_key_exists('merchantName', $request->data())
            && ! array_key_exists('merchantEmail', $request->data()));
    }

    public function test_it_updates_a_subscription(): void
    {
        Http::fake([
            'payments.plorea.no/subscriptions/sub_1*' => Http::response([
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
                'platform' => 'memberflow',
            ]);
    }

    public function test_it_sends_allow_early_charge(): void
    {
        Http::fake([
            'payments.plorea.no/subscriptions/sub_1*' => Http::response([
                'subscriptionId' => 'sub_1',
                'status' => 'active',
                'nextChargeAt' => '2026-11-01T00:00:00.000Z',
            ]),
        ]);

        Plorea::subscriptions()->update('sub_1')
            ->nextChargeAt(new DateTimeImmutable('2026-11-01T00:00:00Z'))
            ->allowEarlyCharge()
            ->save();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && $request->data() === [
                'nextChargeAt' => '2026-11-01T00:00:00.000Z',
                'allowEarlyCharge' => true,
                'platform' => 'memberflow',
            ]);
    }

    public function test_a_next_charge_inside_a_paid_period_throws_with_paid_until(): void
    {
        // Stated by Plorea on 2026-10-14: PATCH answers 409 with paidUntil.
        // Not captured yet, so the body below is constructed.
        Http::fake([
            'payments.plorea.no/subscriptions/sub_1*' => Http::response([
                'error' => 'nextChargeAt is inside a paid period',
                'paidUntil' => '2026-11-14T08:00:00.000Z',
            ], 409),
        ]);

        try {
            Plorea::subscriptions()->update('sub_1')
                ->nextChargeAt(new DateTimeImmutable('2026-11-01T00:00:00Z'))
                ->save();
            $this->fail('Expected NextChargeInPaidPeriodException.');
        } catch (NextChargeInPaidPeriodException $caught) {
            $this->assertSame(409, $caught->status);
            $this->assertSame('2026-11-14T08:00:00+00:00', $caught->paidUntil()?->toIso8601String());
        }
    }

    public function test_a_409_with_a_null_paid_until_still_names_the_paid_period(): void
    {
        Http::fake([
            'payments.plorea.no/subscriptions/sub_1*' => Http::response(['error' => 'Conflict', 'paidUntil' => null], 409),
        ]);

        try {
            Plorea::subscriptions()->update('sub_1')->nextChargeAt(new DateTimeImmutable('2026-11-01T00:00:00Z'))->save();
            $this->fail('Expected NextChargeInPaidPeriodException.');
        } catch (NextChargeInPaidPeriodException $caught) {
            $this->assertNull($caught->paidUntil());
        }
    }

    public function test_a_409_without_paid_until_stays_a_plain_request_exception(): void
    {
        Http::fake([
            'payments.plorea.no/subscriptions/sub_1*' => Http::response(['error' => 'Conflict'], 409),
        ]);

        try {
            Plorea::subscriptions()->update('sub_1')->title('New')->save();
            $this->fail('Expected RequestException.');
        } catch (RequestException $caught) {
            $this->assertNotInstanceOf(NextChargeInPaidPeriodException::class, $caught);
        }
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

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://payments.plorea.no/subscriptions?externalId=ws_acme_456&status=active&platform=memberflow');
    }

    /**
     * With tenantId set, Plorea ignored the externalId filter and returned
     * the whole tenant: 120 rows, 2 of them a match (seen 2026-10-20). The
     * shape here is constructed, not captured. It pins only that a row for
     * another externalId never comes back, and that a row without one is kept.
     * needingAttention() goes through the same list, so it must not flag
     * another customer's subscription.
     */
    public function test_it_drops_rows_for_another_external_id_when_plorea_ignores_the_filter(): void
    {
        Http::fake([
            'payments.plorea.no/subscriptions?*' => Http::response([
                'tenantId' => 'other-tenant',
                'count' => 3,
                'items' => [
                    ['subscriptionId' => 'sub_mine', 'externalId' => 'ws_acme_456', 'status' => 'active', 'nextChargeAt' => '2099-01-01T00:00:00Z'],
                    ['subscriptionId' => 'sub_other', 'externalId' => 'ws_other_789', 'status' => 'payment_failed'],
                    ['subscriptionId' => 'sub_unlabelled', 'status' => 'active', 'nextChargeAt' => '2099-01-01T00:00:00Z'],
                ],
            ]),
        ]);

        $subscriptions = Plorea::subscriptions()->forExternalId('ws_acme_456', tenantId: 'other-tenant');

        $this->assertSame(['sub_mine', 'sub_unlabelled'], $subscriptions->pluck('id')->all());
        $this->assertCount(0, Plorea::subscriptions()->needingAttention('ws_acme_456', tenantId: 'other-tenant'));
    }

    /**
     * The polling half of dunning. Webhooks are never redelivered, so the
     * helper catches the past_due status Plorea reports
     * while retrying (captured 2026-10-01), the documented-but-never-observed
     * payment_failed status, and an overdue nextChargeAt left by a cycle that
     * stalled without any failure status.
     */
    public function test_it_lists_subscriptions_needing_attention(): void
    {
        Http::fake([
            'payments.plorea.no/subscriptions?*' => Http::response([
                'externalId' => 'ws_acme_456',
                'count' => 5,
                'items' => [
                    // Healthy: the scheduler has already moved the date on.
                    ['subscriptionId' => 'sub_ok', 'status' => 'active', 'nextChargeAt' => '2099-01-01T00:00:00Z'],
                    // A failed charge being retried: the date has moved to the
                    // retry, so only the status gives it away.
                    ['subscriptionId' => 'sub_past_due', 'status' => 'past_due', 'retryCount' => 1, 'nextChargeAt' => '2099-01-01T00:00:00Z'],
                    // The documented failure status.
                    ['subscriptionId' => 'sub_failed', 'status' => 'payment_failed', 'nextChargeAt' => '2099-01-01T00:00:00Z'],
                    // Still active, but the charge that was due never landed.
                    ['subscriptionId' => 'sub_stuck', 'status' => 'active', 'nextChargeAt' => '2020-01-01T00:00:00Z'],
                    // Canceled subscriptions keep a stale date and are not overdue.
                    ['subscriptionId' => 'sub_canceled', 'status' => 'canceled', 'nextChargeAt' => '2020-01-01T00:00:00Z'],
                ],
            ]),
        ]);

        $needsAttention = Plorea::subscriptions()->needingAttention('ws_acme_456');

        $this->assertSame(['sub_past_due', 'sub_failed', 'sub_stuck'], $needsAttention->pluck('id')->all());

        // The status filter is deliberately not sent: a server-side filter on
        // one failure status would drop the overdue subscriptions this also
        // exists to find.
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://payments.plorea.no/subscriptions?externalId=ws_acme_456&platform=memberflow');
    }

    public function test_the_overdue_grace_period_is_configurable(): void
    {
        $subscription = Subscription::fromArray([
            'subscriptionId' => 'sub_1',
            'status' => 'active',
            'nextChargeAt' => '2026-09-09T12:00:00Z',
        ]);

        $now = new DateTimeImmutable('2026-09-09T12:30:00+00:00');

        $this->assertFalse($subscription->isOverdue(now: $now));
        $this->assertTrue($subscription->isOverdue(graceMinutes: 10, now: $now));
    }

    public function test_a_subscription_without_a_next_charge_is_never_overdue(): void
    {
        $subscription = Subscription::fromArray(['subscriptionId' => 'sub_1', 'status' => 'active']);

        $this->assertFalse($subscription->isOverdue());
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

        Http::assertSent(fn (Request $request): bool => $request->data() === ['reason' => 'extra_seat', 'platform' => 'memberflow']);
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
            'payments.plorea.no/subscriptions/sub_1/charges?*' => Http::response([
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
            && $request->data() === ['reason' => 'customer_requested', 'platform' => 'memberflow']);
    }
}
