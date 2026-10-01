<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Tests\Feature;

use DateTime;
use DateTimeImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MemberFlow\Plorea\Data\Subscription;
use MemberFlow\Plorea\Exceptions\ValidationException;
use MemberFlow\Plorea\Facades\Plorea;
use MemberFlow\Plorea\Tests\TestCase;

/** Constructed responses for the pause contract stated by Plorea on 2026-10-01. */
class SubscriptionPauseTest extends TestCase
{
    public function test_pause_sends_only_the_paused_status(): void
    {
        Http::fake(['*' => Http::response([
            'subscriptionId' => 'sub_1', 'status' => 'paused', 'nextChargeAt' => null,
        ])]);

        $subscription = Plorea::subscriptions()->pause('sub_1');

        $this->assertTrue($subscription->isPaused());
        $this->assertFalse($subscription->isActive());
        $this->assertNull($subscription->nextChargeAt);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && $request->url() === 'https://payments.plorea.no/subscriptions/sub_1'
            && $request->data() === ['status' => 'paused', 'platform' => 'memberflow']);
        Http::assertSentCount(1);
    }

    public function test_resume_sends_status_and_utc_date_in_one_request_without_mutating_the_date(): void
    {
        Http::fake(['*' => Http::response([
            'subscriptionId' => 'sub_1', 'status' => 'active', 'nextChargeAt' => '2026-11-02T23:00:00.123Z',
        ])]);
        $nextChargeAt = new DateTime('2026-11-03T00:00:00.123+01:00');

        $subscription = Plorea::subscriptions()->resume('sub_1', $nextChargeAt);

        $this->assertTrue($subscription->isActive());
        $this->assertSame($nextChargeAt->getTimestamp(), $subscription->nextChargeAt?->getTimestamp());
        $this->assertSame('+01:00', $nextChargeAt->format('P'));
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && $request->data() === [
                'status' => 'active', 'nextChargeAt' => '2026-11-02T23:00:00.123Z', 'platform' => 'memberflow',
            ]);
        Http::assertSentCount(1);
    }

    public function test_pausing_a_reused_builder_removes_the_previous_resume_date(): void
    {
        Plorea::fake();

        $subscription = Plorea::subscriptions()->update('sub_1')
            ->resumeAt(new DateTimeImmutable('2026-12-01T00:00:00Z'))
            ->pause()->save();

        $this->assertTrue($subscription->isPaused());
        $this->assertNull($subscription->nextChargeAt);
        $this->assertSame(['status' => 'paused', 'platform' => 'memberflow'], Plorea::recorded()->sole()->data);
    }

    public function test_the_fake_returns_the_explicit_resume_date(): void
    {
        Plorea::fake();

        $subscription = Plorea::subscriptions()->update('sub_1')->pause()
            ->resumeAt(new DateTimeImmutable('2026-12-01T00:00:00Z'))->save();

        $this->assertTrue($subscription->isActive());
        $this->assertSame('2026-12-01T00:00:00+00:00', $subscription->nextChargeAt?->toIso8601String());
    }

    public function test_paused_subscriptions_are_not_overdue_even_with_a_stale_date(): void
    {
        $subscription = Subscription::fromArray([
            'subscriptionId' => 'sub_1', 'status' => 'paused', 'nextChargeAt' => '2026-10-01T00:00:00Z',
        ]);

        $this->assertFalse($subscription->isOverdue(now: new DateTimeImmutable('2026-12-01T00:00:00Z')));
        Http::fake(['*' => Http::response(['items' => [$subscription->raw]])]);
        $this->assertCount(0, Plorea::subscriptions()->needingAttention('member_1'));
    }

    public function test_a_rejected_pause_propagates_the_provider_error(): void
    {
        Http::fake(['*' => Http::response(['error' => 'Subscription cannot be paused'], 400)]);

        $this->expectException(ValidationException::class);
        Plorea::subscriptions()->pause('sub_1');
    }
}
