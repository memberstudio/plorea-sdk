<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MemberFlow\Plorea\Enums\RecurringType;
use MemberFlow\Plorea\Exceptions\PaymentMethodInUseException;
use MemberFlow\Plorea\Exceptions\PloreaException;
use MemberFlow\Plorea\Exceptions\ValidationException;
use MemberFlow\Plorea\Facades\Plorea;
use MemberFlow\Plorea\Testing\RecordedRequest;
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
            'platform' => 'memberflow',
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

    public function test_a_drop_in_session_never_takes_id_as_the_session_id(): void
    {
        // A card-setup response also carries a payment method id. Only the
        // payment session accepts Adyen's own id/data names.
        Http::fake([
            'payments.plorea.no/payment-methods/setup/session' => Http::response([
                'paymentMethodId' => 'pm_123',
                'id' => 'pm_123',
                'data' => 'AbData',
                'status' => 'pending_setup',
            ], 201),
        ]);

        $this->expectException(PloreaException::class);
        $this->expectExceptionMessage('without a session id');

        Plorea::paymentMethods()
            ->setup('customer-1', RecurringType::Subscription, 'https://app.test/return')
            ->session();
    }

    public function test_it_sends_the_merchant_on_a_drop_in_session(): void
    {
        Http::fake([
            'payments.plorea.no/payment-methods/setup/session' => Http::response([
                'paymentMethodId' => 'pm_123',
                'sessionId' => 'CS123',
                'sessionData' => 'AbData',
                'status' => 'pending_setup',
            ], 201),
        ]);

        Plorea::paymentMethods()
            ->setup('customer-1', RecurringType::Subscription, 'https://app.test/return')
            ->merchant('999999999', 'Example Gym AS')
            ->session();

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://payments.plorea.no/payment-methods/setup/session'
            && $request->data()['merchantOrgNr'] === '999999999'
            && $request->data()['merchantName'] === 'Example Gym AS');
    }

    public function test_a_drop_in_session_merchant_without_a_name_sends_no_name(): void
    {
        Http::fake([
            'payments.plorea.no/payment-methods/setup/session' => Http::response([
                'paymentMethodId' => 'pm_123',
                'sessionId' => 'CS123',
                'sessionData' => 'AbData',
                'status' => 'pending_setup',
            ], 201),
        ]);

        Plorea::paymentMethods()
            ->setup('customer-1', RecurringType::Subscription, 'https://app.test/return')
            ->merchant('999999999')
            ->session();

        Http::assertSent(fn (Request $request): bool => $request->data()['merchantOrgNr'] === '999999999'
            && ! array_key_exists('merchantName', $request->data()));
    }

    public function test_the_hosted_setup_sends_the_merchant(): void
    {
        Http::fake([
            'payments.plorea.no/payment-methods/setup' => Http::response([
                'paymentMethodId' => 'pm_123',
                'status' => 'pending_setup',
            ], 201),
        ]);

        Plorea::paymentMethods()
            ->setup('customer-1', RecurringType::Subscription, 'https://app.test/return')
            ->merchant('999999999', 'Example Gym AS')
            ->create();

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://payments.plorea.no/payment-methods/setup'
            && $request->data()['merchantOrgNr'] === '999999999'
            && $request->data()['merchantName'] === 'Example Gym AS');
    }

    public function test_a_drop_in_session_without_a_merchant_sends_no_merchant(): void
    {
        Http::fake([
            'payments.plorea.no/payment-methods/setup/session' => Http::response([
                'paymentMethodId' => 'pm_123',
                'sessionId' => 'CS123',
                'sessionData' => 'AbData',
                'status' => 'pending_setup',
            ], 201),
        ]);

        Plorea::paymentMethods()
            ->setup('customer-1', RecurringType::Subscription, 'https://app.test/return')
            ->session();

        Http::assertSent(fn (Request $request): bool => ! array_key_exists('merchantOrgNr', $request->data()));
    }

    public function test_it_finds_a_payment_method(): void
    {
        Http::fake([
            'payments.plorea.no/payment-methods/pm_123?*' => Http::response([
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

    public function test_it_maps_the_card_fields_added_on_2026_10_14(): void
    {
        // Verified in test on 2026-10-14 on a read of a Visa test card: every
        // new field is present, the wallet and token fields are null, and the
        // older card fields remain. Constructed from that read, not a capture.
        Http::fake([
            'payments.plorea.no/payment-methods/pm_new?*' => Http::response([
                'paymentMethodId' => 'pm_new',
                'status' => 'active',
                'cardLast4' => '1111',
                'cardBrand' => 'visa',
                'expiryDate' => '03/2030',
                'last4' => '1111',
                'brand' => 'visa',
                'expiryMonth' => 3,
                'expiryYear' => 2030,
                'fundingSource' => null,
                'walletType' => null,
                'isToken' => null,
                'tokenLast4' => null,
                'physicalCardLast4' => '1111',
                'physicalCardExpiryMonth' => 3,
                'physicalCardExpiryYear' => 2030,
            ]),
            'payments.plorea.no/payment-methods/pm_old?*' => Http::response([
                'paymentMethodId' => 'pm_old',
                'status' => 'active',
                'cardLast4' => '0004',
            ]),
        ]);

        $method = Plorea::paymentMethods()->find('pm_new');

        $this->assertSame('1111', $method->last4);
        $this->assertSame('visa', $method->brand);
        $this->assertSame(3, $method->expiryMonth);
        $this->assertSame(2030, $method->expiryYear);
        $this->assertNull($method->fundingSource);
        $this->assertNull($method->walletType);
        $this->assertNull($method->isToken);
        $this->assertNull($method->tokenLast4);
        $this->assertSame('1111', $method->physicalCardLast4);
        $this->assertSame(3, $method->physicalCardExpiryMonth);
        $this->assertSame(2030, $method->physicalCardExpiryYear);
        $this->assertSame('1111', $method->cardLast4);

        $old = Plorea::paymentMethods()->find('pm_old');

        $this->assertNull($old->last4);
        $this->assertNull($old->physicalCardLast4);
        $this->assertSame('0004', $old->cardLast4);
    }

    public function test_it_reads_whether_a_delete_disabled_the_card_at_adyen(): void
    {
        // Stated by Plorea on 2026-10-14, test only: the delete response has
        // adyenTokenDisabled. Not captured yet, so this pins only the mapping.
        Http::fake([
            'payments.plorea.no/payment-methods/pm_new' => Http::response([
                'paymentMethodId' => 'pm_new',
                'status' => 'cancelled',
                'previousStatus' => 'active',
                'adyenTokenDisabled' => true,
            ]),
            'payments.plorea.no/payment-methods/pm_old' => Http::response([
                'paymentMethodId' => 'pm_old',
                'status' => 'cancelled',
                'previousStatus' => 'active',
            ]),
        ]);

        $this->assertTrue(Plorea::paymentMethods()->delete('pm_new')->adyenTokenDisabled);
        $this->assertNull(Plorea::paymentMethods()->delete('pm_old')->adyenTokenDisabled);
    }

    public function test_it_deletes_a_payment_method(): void
    {
        Http::fake([
            'payments.plorea.no/payment-methods/pm_123' => Http::response([
                'paymentMethodId' => 'pm_123',
                'status' => 'cancelled',
                'previousStatus' => 'active',
                'cancelledAt' => '2026-10-06T09:49:58.709Z',
            ]),
        ]);

        $cancellation = Plorea::paymentMethods()->delete('pm_123');

        $this->assertTrue($cancellation->isCancelled());
        $this->assertSame('active', $cancellation->previousStatus);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://payments.plorea.no/payment-methods/pm_123'
            && $request->data() === ['platform' => 'memberflow']);
    }

    public function test_a_plain_bad_request_is_not_mistaken_for_a_method_in_use(): void
    {
        Http::fake([
            'payments.plorea.no/payment-methods/pm_123' => Http::response(['error' => 'Invalid payment method id'], 400),
        ]);

        try {
            Plorea::paymentMethods()->delete('pm_123');
            $this->fail('Expected ValidationException.');
        } catch (ValidationException $caught) {
            $this->assertNotInstanceOf(PaymentMethodInUseException::class, $caught);
        }
    }

    public function test_the_fake_cancels_and_then_reports_already_cancelled(): void
    {
        Plorea::fake();

        $first = Plorea::paymentMethods()->delete('pm_123');
        $again = Plorea::paymentMethods()->delete('pm_123');
        $read = Plorea::paymentMethods()->find('pm_123');

        $this->assertFalse($first->alreadyCancelled);
        $this->assertSame('active', $first->previousStatus);
        $this->assertTrue($again->alreadyCancelled);
        $this->assertNull($again->cancelledAt);
        $this->assertTrue($read->isCancelled());
        $this->assertTrue(Plorea::paymentMethods()->find('pm_other')->isActive());
        $this->assertSame('0004', $read->physicalCardLast4);
        Plorea::assertSent(fn (RecordedRequest $request): bool => $request->matches('DELETE payment-methods/pm_123'));
    }

    public function test_a_failed_fake_delete_does_not_cancel_the_method(): void
    {
        Plorea::fake(['DELETE payment-methods/*' => new ValidationException('Payment method is attached to active subscriptions', 400)]);

        try {
            Plorea::paymentMethods()->delete('pm_123');
        } catch (ValidationException) {
        }

        $this->assertTrue(Plorea::paymentMethods()->find('pm_123')->isActive());
    }
}
