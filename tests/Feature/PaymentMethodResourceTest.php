<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MemberFlow\Plorea\Enums\RecurringType;
use MemberFlow\Plorea\Facades\Plorea;
use MemberFlow\Plorea\Tests\TestCase;

class PaymentMethodResourceTest extends TestCase
{
    public function test_it_creates_a_hosted_setup(): void
    {
        Http::fake([
            'payments.plorea.no/payment-methods/setup' => Http::response([
                'paymentMethodId' => 'pm_123',
                'tenantId' => 'test-tenant',
                'shopperReference' => 'customer-1',
                'recurringType' => 'Subscription',
                'status' => 'pending_setup',
                'adyenPaymentLinkUrl' => 'https://test.adyen.link/PL123',
            ], 201),
        ]);

        $method = Plorea::paymentMethods()
            ->setup('customer-1', RecurringType::Subscription, 'https://app.test/return')
            ->customerId('cust_1')
            ->description('Save card for future charges')
            ->create();

        $this->assertSame('pm_123', $method->id);
        $this->assertSame(RecurringType::Subscription, $method->recurringType);
        $this->assertFalse($method->isActive());
        $this->assertSame('https://test.adyen.link/PL123', $method->adyenPaymentLinkUrl);

        Http::assertSent(fn (Request $request): bool => $request->data() === [
            'tenantId' => 'test-tenant',
            'customerId' => 'cust_1',
            'shopperReference' => 'customer-1',
            'recurringType' => 'Subscription',
            'returnUrl' => 'https://app.test/return',
            'description' => 'Save card for future charges',
        ]);
    }

    public function test_it_creates_a_drop_in_session(): void
    {
        Http::fake([
            'payments.plorea.no/payment-methods/setup/session' => Http::response([
                'paymentMethodId' => 'pm_123',
                'sessionId' => 'CS123',
                'sessionData' => 'AbData',
                'status' => 'pending_setup',
                'expiresAt' => '2026-04-23T21:09:16+02:00',
            ], 201),
        ]);

        $session = Plorea::paymentMethods()
            ->setup('customer-1', RecurringType::Subscription, 'https://app.test/return')
            ->doneId('done_usr_1')
            ->session();

        $this->assertSame('pm_123', $session->paymentMethodId);
        $this->assertSame('CS123', $session->sessionId);
        $this->assertSame('AbData', $session->sessionData);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://payments.plorea.no/payment-methods/setup/session'
            && $request->data()['doneId'] === 'done_usr_1');
    }

    public function test_it_finds_a_payment_method(): void
    {
        Http::fake([
            'payments.plorea.no/payment-methods/pm_123' => Http::response([
                'paymentMethodId' => 'pm_123',
                'status' => 'active',
                'storedPaymentMethodId' => 'MQHL6N6G2P8K63W5',
                'cardLast4' => '0004',
                'cardBrand' => 'mc',
            ]),
        ]);

        $method = Plorea::paymentMethods()->find('pm_123');

        $this->assertTrue($method->isActive());
        $this->assertSame('0004', $method->cardLast4);
    }
}
