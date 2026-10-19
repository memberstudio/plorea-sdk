<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Tests\Unit;

use MemberFlow\Plorea\Data\PaymentStatus;
use PHPUnit\Framework\TestCase;

class PaymentStatusTest extends TestCase
{
    public function test_a_subscription_charge_needs_both_ids(): void
    {
        $this->assertTrue(PaymentStatus::fromArray([
            'reference' => 'sub_a-chg_b',
            'subscriptionId' => 'sub_a',
            'chargeId' => 'chg_b',
        ])->isSubscriptionCharge());

        $this->assertFalse(PaymentStatus::fromArray([
            'reference' => 'sub_a-chg_b',
            'subscriptionId' => 'sub_a',
            'chargeId' => '',
        ])->isSubscriptionCharge());

        $this->assertFalse(PaymentStatus::fromArray([
            'reference' => 'sub_a-chg_b',
            'chargeId' => 'chg_b',
        ])->isSubscriptionCharge());
    }

    public function test_a_payment_link_tagged_subscription_is_not_a_subscription_charge(): void
    {
        $status = PaymentStatus::fromArray([
            'reference' => 'FIN-2026-00123',
            'platform' => 'subscription',
        ]);

        $this->assertFalse($status->isSubscriptionCharge());
    }

    public function test_a_refused_cancel_is_detected(): void
    {
        $this->assertTrue(PaymentStatus::fromArray([
            'reference' => 'sub_a-chg_b',
            'status' => 'cancelled',
            'webhookEventCode' => 'CANCELLATION',
            'webhookSuccess' => false,
        ])->isCancellationRefused());

        $this->assertFalse(PaymentStatus::fromArray([
            'reference' => 'sub_a-chg_b',
            'status' => 'cancelled',
            'webhookEventCode' => 'CANCELLATION',
            'webhookSuccess' => true,
        ])->isCancellationRefused());
    }

    public function test_refunds_default_to_an_empty_list(): void
    {
        $status = PaymentStatus::fromArray(['reference' => 'FIN-2026-00123', 'refunds' => 'not-a-list']);

        $this->assertSame([], $status->refunds);
        $this->assertNull($status->refundedTotal);
    }
}
