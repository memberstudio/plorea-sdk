<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Tests\Feature;

use Illuminate\Support\Facades\Http;
use MemberFlow\Plorea\Data\Amount;
use MemberFlow\Plorea\Data\BillingInterval;
use MemberFlow\Plorea\Enums\RecurringType;
use MemberFlow\Plorea\Exceptions\AuthenticationException;
use MemberFlow\Plorea\Exceptions\NotFoundException;
use MemberFlow\Plorea\Exceptions\ValidationException;
use MemberFlow\Plorea\Facades\Plorea;
use MemberFlow\Plorea\Tests\TestCase;

/**
 * Runs anonymized real API responses (captured from Plorea's test
 * environment) through the SDK, proving every DTO parses the true
 * response shapes — including the undocumented fields the docs omit.
 *
 * The fixtures in tests/Fixtures/ keep the full key set of the live
 * responses; only identifying values (tenant, merchant, link id,
 * reference, dates) are replaced with stable fakes.
 */
class GoldenFixturesTest extends TestCase
{
    public function test_it_parses_a_real_payment_link_created_response(): void
    {
        Http::fake([
            'payments.plorea.no/payments/link' => Http::response($this->fixture('payment-link-created')),
        ]);

        $link = Plorea::payments()
            ->link('GOLDEN-2026-001', 'Golden fixture product', Amount::nok(1000), 'https://example.com/return')
            ->merchant(orgNr: '912650774')
            ->create();

        $this->assertSame('created', $link->status);
        $this->assertSame('test', $link->environment);
        $this->assertSame('https://pay.plorea.no/pl_test_golden_link', $link->url);
        $this->assertSame('pl_test_golden_link', $link->id);
        $this->assertSame('GOLDEN-2026-001', $link->reference);
        $this->assertSame('test-tenant', $link->tenantId);
        $this->assertSame('TestMerchantAccount', $link->merchantAccount);
        $this->assertSame('Test Store AS', $link->store);
        $this->assertSame('BA_TEST_BALANCE_ACCOUNT', $link->balanceAccountId);
        $this->assertFalse($link->splitsEnabled);
        $this->assertFalse($link->partnerSplitsApplied);
        $this->assertSame('plorea', $link->provider);
        $this->assertSame('2099-12-31 12:00:00', $link->expiresAt?->format('Y-m-d H:i:s'));

        $this->assertSame('payments_only', $link->raw['clientType']);
        $this->assertFalse($link->raw['skipKyc']);
        $this->assertNull($link->raw['themeId']);
    }

    public function test_it_parses_a_real_open_payment_status_response(): void
    {
        Http::fake([
            'payments.plorea.no/payments/status/GOLDEN-2026-001' => Http::response($this->fixture('payment-status-open')),
        ]);

        $status = Plorea::payments()->status('GOLDEN-2026-001');

        $this->assertSame('GOLDEN-2026-001', $status->reference);
        $this->assertSame('created', $status->status);
        $this->assertTrue($status->isOpen());
        $this->assertFalse($status->isPaid());
        $this->assertSame('adyen', $status->provider);
        $this->assertSame('unknown', $status->platform);
        $this->assertNull($status->orderId);
        $this->assertNull($status->pspReference);
        $this->assertSame('pl_test_golden_link', $status->paymentLinkId);
        $this->assertSame(1000, $status->amount?->value);
        $this->assertSame('NOK', $status->amount->currency);
        $this->assertSame('TestMerchantAccount', $status->merchantAccount);
        $this->assertSame('Test Store AS', $status->store);
        $this->assertSame('2026-08-26 12:00:00', $status->createdAt?->format('Y-m-d H:i:s'));
        $this->assertNull($status->webhookEventCode);
        $this->assertNull($status->webhookSuccess);
        $this->assertNull($status->lastWebhookAt);
        $this->assertNull($status->lastRefundReference);
        $this->assertNull($status->lastCancelReference);
    }

    public function test_it_parses_a_real_paid_payment_status_response(): void
    {
        Http::fake([
            'payments.plorea.no/payments/status/GOLDEN-2026-001' => Http::response($this->fixture('payment-status-paid')),
        ]);

        $status = Plorea::payments()->status('GOLDEN-2026-001');

        $this->assertSame('authorised', $status->status);
        $this->assertTrue($status->isAuthorised());
        $this->assertTrue($status->isPaid());
        $this->assertFalse($status->isOpen());
        $this->assertSame('TESTPSPREF000001', $status->pspReference);
        $this->assertSame(1000, $status->amount?->value);
        $this->assertSame('AUTHORISATION', $status->webhookEventCode);
        $this->assertTrue($status->webhookSuccess);
        $this->assertSame('2026-08-26 12:05:00', $status->lastWebhookAt?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-26 12:05:00', $status->updatedAt?->format('Y-m-d H:i:s'));
        $this->assertNull($status->lastRefundReference);
        $this->assertNull($status->lastCancelReference);
    }

    public function test_it_parses_a_real_refund_response(): void
    {
        Http::fake([
            'payments.plorea.no/payments/refund' => Http::response($this->fixture('refund-created')),
        ]);

        $refund = Plorea::payments()->refund(
            'GOLDEN-2026-001',
            'GOLDEN-2026-001-REFUND-1',
            reason: 'Customer requested refund',
        );

        $this->assertSame('refund_requested', $refund->status);
        $this->assertSame('GOLDEN-2026-001', $refund->reference);
        $this->assertSame('GOLDEN-2026-001-REFUND-1', $refund->modificationReference);
        $this->assertSame('TESTREFUNDPSP001', $refund->refundPspReference);
        $this->assertSame('TESTPSPREF000001', $refund->paymentPspReference);
        $this->assertSame(1000, $refund->amount?->value);
        $this->assertSame('NOK', $refund->amount->currency);
        $this->assertSame('test', $refund->environment);
    }

    public function test_it_parses_a_real_cancel_response(): void
    {
        Http::fake([
            'payments.plorea.no/payments/cancel' => Http::response($this->fixture('cancel-created')),
        ]);

        $cancellation = Plorea::payments()->cancel('GOLDEN-2026-002', 'GOLDEN-2026-002-CANCEL-1');

        $this->assertSame('cancel_requested', $cancellation->status);
        $this->assertSame('GOLDEN-2026-002', $cancellation->reference);
        $this->assertSame('GOLDEN-2026-002-CANCEL-1', $cancellation->modificationReference);
        $this->assertSame('TESTCANCELPSP001', $cancellation->cancelPspReference);
        $this->assertSame('TESTPSPREF000002', $cancellation->paymentPspReference);
        $this->assertSame('test', $cancellation->environment);
    }

    public function test_it_parses_a_real_refund_requested_status_response(): void
    {
        Http::fake([
            'payments.plorea.no/payments/status/GOLDEN-2026-001' => Http::response($this->fixture('payment-status-refund-requested')),
        ]);

        $status = Plorea::payments()->status('GOLDEN-2026-001');

        $this->assertSame('refund_requested', $status->status);
        $this->assertTrue($status->isRefundRequested());
        $this->assertFalse($status->isCancelRequested());
        $this->assertFalse($status->isPaid());
        $this->assertFalse($status->isOpen());
        $this->assertSame('GOLDEN-2026-001-REFUND-1', $status->lastRefundReference);
        $this->assertSame('TESTREFUNDPSP001', $status->lastRefundRequestPspReference);
        $this->assertSame(1000, $status->lastRefundAmount);
        $this->assertSame('Customer requested refund', $status->lastRefundReason);
        $this->assertSame('2026-08-26 12:10:00', $status->lastRefundRequestedAt?->format('Y-m-d H:i:s'));
        $this->assertNull($status->lastCancelReference);
    }

    public function test_it_parses_a_real_cancel_requested_status_response(): void
    {
        Http::fake([
            'payments.plorea.no/payments/status/GOLDEN-2026-002' => Http::response($this->fixture('payment-status-cancel-requested')),
        ]);

        $status = Plorea::payments()->status('GOLDEN-2026-002');

        $this->assertSame('cancel_requested', $status->status);
        $this->assertTrue($status->isCancelRequested());
        $this->assertFalse($status->isRefundRequested());
        $this->assertFalse($status->isPaid());
        $this->assertFalse($status->isOpen());
        $this->assertSame('GOLDEN-2026-002-CANCEL-1', $status->lastCancelReference);
        $this->assertSame('TESTCANCELPSP001', $status->lastCancelRequestPspReference);
        $this->assertSame('2026-08-26 12:10:00', $status->lastCancelRequestedAt?->format('Y-m-d H:i:s'));
        $this->assertNull($status->lastRefundReference);
    }

    public function test_it_parses_a_real_pay_page_response(): void
    {
        Http::fake([
            'payments.plorea.no/pay/pl_test_golden_link' => Http::response($this->fixture('pay-page')),
        ]);

        $link = Plorea::payByLink()->find('pl_test_golden_link');

        $this->assertSame('pl_test_golden_link', $link->id);
        $this->assertSame('test-tenant', $link->tenantId);
        $this->assertSame('GOLDEN-2026-001', $link->reference);
        $this->assertSame('Golden fixture product', $link->product);
        $this->assertSame(1000, $link->amount?->value);
        $this->assertSame('test', $link->environment);
        $this->assertNull($link->merchantName);
        $this->assertNull($link->merchantOrgNr);
        $this->assertSame('https://example.com/return', $link->returnUrl);
        $this->assertNull($link->invoiceUrl);
        $this->assertSame('2099-12-31 12:00:00', $link->expiresAt?->format('Y-m-d H:i:s'));
        $this->assertFalse($link->expired);

        $this->assertSame('TestMerchantAccount', $link->raw['merchantAccount']);
        $this->assertNull($link->raw['partnerSplits']);
    }

    public function test_it_maps_a_real_unknown_reference_response_to_not_found(): void
    {
        Http::fake([
            'payments.plorea.no/payments/status/GOLDEN-2026-UNKNOWN' => Http::response($this->fixture('payment-status-not-found'), 404),
        ]);

        try {
            Plorea::payments()->status('GOLDEN-2026-UNKNOWN');
            $this->fail('Expected NotFoundException.');
        } catch (NotFoundException $caught) {
            $this->assertSame('Payment not found', $caught->getMessage());
            $this->assertSame(404, $caught->status);
        }
    }

    public function test_it_parses_a_real_hosted_payment_method_setup_response(): void
    {
        Http::fake([
            'payments.plorea.no/payment-methods/setup' => Http::response($this->fixture('payment-method-setup-hosted')),
        ]);

        $method = Plorea::paymentMethods()
            ->setup('golden-shopper-001', RecurringType::Subscription, 'https://example.com/return')
            ->description('Golden fixture capture')
            ->create();

        $this->assertSame('pm_test_golden_method', $method->id);
        $this->assertSame('test-tenant', $method->tenantId);
        $this->assertSame('golden-shopper-001', $method->shopperReference);
        $this->assertSame(RecurringType::Subscription, $method->recurringType);
        $this->assertSame('pending_setup', $method->status);
        $this->assertTrue($method->isPendingSetup());
        $this->assertFalse($method->isActive());
        $this->assertSame('PL_TEST_SETUP_LINK', $method->adyenPaymentLinkId);
        $this->assertSame('https://test.adyen.link/PL_TEST_SETUP_LINK', $method->adyenPaymentLinkUrl);
        $this->assertSame('2026-09-05 09:51:00', $method->expiresAt?->utc()->format('Y-m-d H:i:s'));

        // The hosted setup response carries none of the card fields yet.
        $this->assertNull($method->storedPaymentMethodId);
        $this->assertNull($method->cardLast4);
    }

    public function test_it_parses_a_real_drop_in_setup_session_response(): void
    {
        Http::fake([
            'payments.plorea.no/payment-methods/setup/session' => Http::response($this->fixture('payment-method-setup-session')),
        ]);

        $session = Plorea::paymentMethods()
            ->setup('golden-shopper-dropin', RecurringType::Subscription, 'https://example.com/return')
            ->session();

        $this->assertSame('pm_test_golden_session', $session->paymentMethodId);
        $this->assertSame('CS_TEST_SESSION', $session->sessionId);
        $this->assertSame('test-session-data', $session->sessionData);
        $this->assertSame('pending_setup', $session->status);
        $this->assertSame('test', $session->environment);

        // The session response is shaped like a payment session: it carries a
        // 1 NOK verification amount Adyen authorises (and reverses) to store
        // the card. It is not a charge and never settles.
        $this->assertSame(100, $session->raw['amount']);
        $this->assertSame('NOK', $session->raw['currency']);
        $this->assertNull($session->raw['reference']);
        $this->assertFalse($session->raw['skipKyc']);
    }

    public function test_it_parses_a_real_pending_payment_method_response(): void
    {
        Http::fake([
            'payments.plorea.no/payment-methods/pm_test_golden_method' => Http::response($this->fixture('payment-method-pending')),
        ]);

        $method = Plorea::paymentMethods()->find('pm_test_golden_method');

        // Fetched before the customer finished the hosted flow: the link is
        // still live and no card exists yet.
        $this->assertTrue($method->isPendingSetup());
        $this->assertFalse($method->isActive());
        $this->assertFalse($method->hasFailed());
        $this->assertSame('PL_TEST_SETUP_LINK', $method->adyenPaymentLinkId);
        $this->assertNull($method->storedPaymentMethodId);
        $this->assertNull($method->cardBrand);
        $this->assertNull($method->expiryDate);
    }

    public function test_it_parses_a_real_active_payment_method_response(): void
    {
        Http::fake([
            'payments.plorea.no/payment-methods/pm_test_golden_method' => Http::response($this->fixture('payment-method-active')),
        ]);

        $method = Plorea::paymentMethods()->find('pm_test_golden_method');

        $this->assertTrue($method->isActive());
        $this->assertSame('TESTSTORED000001', $method->storedPaymentMethodId);
        $this->assertSame('TESTSETUPPSP0001', $method->setupPspReference);
        $this->assertSame('pmsetup_pm_test_golden_method', $method->adyenReference);
        $this->assertSame('1111', $method->cardLast4);
        $this->assertSame('visa', $method->cardBrand);
        $this->assertSame('03/2030', $method->expiryDate);
        $this->assertSame('2026-09-04 09:54:00', $method->consentAt?->utc()->format('Y-m-d H:i:s'));
        $this->assertSame([], $method->metadata);
    }

    public function test_it_parses_a_real_failed_payment_method_response(): void
    {
        Http::fake([
            'payments.plorea.no/payment-methods/pm_test_golden_failed' => Http::response($this->fixture('payment-method-failed')),
        ]);

        $method = Plorea::paymentMethods()->find('pm_test_golden_failed');

        // Adyen refusing the setup verification leaves the method "failed"
        // with no stored card at all — never chargeable, so a new setup is
        // the only way forward.
        $this->assertSame('failed', $method->status);
        $this->assertTrue($method->hasFailed());
        $this->assertFalse($method->isActive());
        $this->assertNull($method->storedPaymentMethodId);
        $this->assertNull($method->setupPspReference);
        $this->assertNull($method->consentAt);
    }

    public function test_it_parses_a_real_subscription_created_response(): void
    {
        Http::fake([
            'payments.plorea.no/subscriptions' => Http::response($this->fixture('subscription-created')),
        ]);

        $subscription = Plorea::subscriptions()
            ->create('pm_test_golden_method', Amount::nok(24900), BillingInterval::daily())
            ->externalId('GOLDEN-EXT-001')
            ->title('Golden fixture plan')
            ->retryPolicy(2, retryIntervalDays: 1)
            ->vat(rate: 0.25, amount: 4980)
            ->save();

        $this->assertSame('sub_test_golden', $subscription->id);
        $this->assertSame('test-tenant', $subscription->tenantId);
        $this->assertSame('pm_test_golden_method', $subscription->paymentMethodId);
        $this->assertTrue($subscription->isActive());
        $this->assertSame(24900, $subscription->amount?->value);
        $this->assertSame('day', $subscription->interval?->unit->value);
        $this->assertSame(1, $subscription->interval->count);
        $this->assertSame(2, $subscription->retryPolicy?->maxRetries);
        $this->assertSame(1, $subscription->retryPolicy->retryIntervalDays);
        $this->assertSame('GOLDEN-EXT-001', $subscription->externalId);
        $this->assertSame(0.25, $subscription->vatRate);
        $this->assertSame(4980, $subscription->vatAmount);
        $this->assertNull($subscription->trialEndsAt);

        // Billing starts immediately: the create response already carries the
        // first nextChargeAt, and the scheduler picks it up within seconds.
        $this->assertSame('2026-09-04 09:55:00', $subscription->nextChargeAt?->utc()->format('Y-m-d H:i:s'));

        // Fields the create response omits but the GET carries.
        $this->assertNull($subscription->shopperReference);
        $this->assertNull($subscription->retryCount);
    }

    public function test_it_parses_a_real_active_subscription_response(): void
    {
        Http::fake([
            'payments.plorea.no/subscriptions/sub_test_golden' => Http::response($this->fixture('subscription-active')),
        ]);

        $subscription = Plorea::subscriptions()->find('sub_test_golden');

        $this->assertTrue($subscription->isActive());
        $this->assertSame('golden-shopper-001', $subscription->shopperReference);
        $this->assertSame('TESTSTORED000001', $subscription->storedPaymentMethodId);
        $this->assertSame(2, $subscription->quantity);
        $this->assertSame(0, $subscription->retryCount);
        $this->assertSame('2026-09-04 09:55:00', $subscription->lastChargeAt?->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(
            'sub_test_golden-chg_test_golden_1',
            $subscription->lastPaymentReference,
        );
        $this->assertNull($subscription->failureReason);
        $this->assertNull($subscription->canceledAt);
        $this->assertNull($subscription->accessEndsAt);
    }

    public function test_it_parses_a_real_canceled_subscription_response(): void
    {
        Http::fake([
            'payments.plorea.no/subscriptions/sub_test_golden' => Http::response($this->fixture('subscription-canceled')),
        ]);

        $subscription = Plorea::subscriptions()->find('sub_test_golden');

        $this->assertTrue($subscription->isCanceled());
        $this->assertFalse($subscription->isActive());
        $this->assertSame('customer_requested', $subscription->cancelReason);
        $this->assertSame('2026-09-04 10:04:00', $subscription->canceledAt?->utc()->format('Y-m-d H:i:s'));

        // Cancelling clears the schedule but leaves access paid up to the end
        // of the interval already charged for.
        $this->assertNull($subscription->nextChargeAt);
        $this->assertSame('2026-09-05 09:55:00', $subscription->accessEndsAt?->utc()->format('Y-m-d H:i:s'));
    }

    public function test_it_parses_a_real_subscription_list_response(): void
    {
        Http::fake([
            'payments.plorea.no/subscriptions?externalId=GOLDEN-EXT-001' => Http::response($this->fixture('subscription-list')),
        ]);

        $subscriptions = Plorea::subscriptions()->forExternalId('GOLDEN-EXT-001');

        $this->assertCount(1, $subscriptions);
        $this->assertSame('sub_test_golden', $subscriptions->first()?->id);
        $this->assertSame('GOLDEN-EXT-001', $subscriptions->first()->externalId);
        $this->assertTrue($subscriptions->first()->isActive());

        // List items are a trimmed projection: no VAT, retry or charge fields.
        $this->assertNull($subscriptions->first()->vatRate);
        $this->assertNull($subscriptions->first()->retryPolicy);
        $this->assertNull($subscriptions->first()->lastPaymentReference);
    }

    public function test_it_parses_a_real_subscription_update_response(): void
    {
        Http::fake([
            'payments.plorea.no/subscriptions/sub_test_golden' => Http::response($this->fixture('subscription-updated')),
        ]);

        $subscription = Plorea::subscriptions()->update('sub_test_golden')
            ->amount(Amount::nok(29900))
            ->quantity(2)
            ->save();

        $this->assertSame(29900, $subscription->amount?->value);
        $this->assertSame(2, $subscription->quantity);
        $this->assertTrue($subscription->isActive());

        // The update response echoes the whole subscription, not just the
        // changed fields — but omits the charge history fields the GET has.
        $this->assertSame('Golden fixture plan', $subscription->title);
        $this->assertNull($subscription->lastPaymentReference);
    }

    public function test_it_parses_a_real_manual_charge_response(): void
    {
        Http::fake([
            'payments.plorea.no/subscriptions/sub_test_golden/charge' => Http::response($this->fixture('subscription-charge-created')),
        ]);

        $charge = Plorea::subscriptions()->charge('sub_test_golden');

        // The POST reports the charge was created; the authorisation result
        // lives in resultCode, and the charge history later reports the
        // settled status ("authorised").
        $this->assertSame('charge_created', $charge->status);
        $this->assertSame('Authorised', $charge->resultCode);
        $this->assertSame('chg_test_golden_1', $charge->id);
        $this->assertSame('sub_test_golden', $charge->subscriptionId);
        $this->assertSame('sub_test_golden-chg_test_golden_1', $charge->reference);
        $this->assertSame('TESTCHARGEPSP001', $charge->pspReference);
        $this->assertSame(29900, $charge->amount?->value);
        $this->assertSame(0.25, $charge->vatRate);
        $this->assertSame(4980, $charge->vatAmount);
        $this->assertSame('2026-09-05 09:55:00', $charge->nextChargeAt?->utc()->format('Y-m-d H:i:s'));
    }

    public function test_it_parses_a_real_charge_history_response(): void
    {
        Http::fake([
            'payments.plorea.no/subscriptions/sub_test_golden/charges' => Http::response($this->fixture('subscription-charges')),
        ]);

        $charges = Plorea::subscriptions()->charges('sub_test_golden');

        $this->assertCount(2, $charges);

        // Newest first: the scheduler's own charge, then the manual one.
        $this->assertSame('scheduled_charge', $charges->first()?->reason);
        $this->assertSame('manual_charge', $charges->last()?->reason);

        $this->assertSame('authorised', $charges->first()->status);
        $this->assertSame('chg_test_golden_2', $charges->first()->id);
        $this->assertSame('TESTCHARGEPSP002', $charges->first()->pspReference);
        $this->assertSame(0, $charges->first()->retryNumber);
        $this->assertNull($charges->first()->failureReason);

        // History items carry no resultCode — that is a create-time field.
        $this->assertNull($charges->first()->resultCode);
    }

    public function test_it_parses_a_real_subscription_cancel_response(): void
    {
        Http::fake([
            'payments.plorea.no/subscriptions/sub_test_golden/cancel' => Http::response($this->fixture('subscription-cancel')),
        ]);

        $cancellation = Plorea::subscriptions()->cancel('sub_test_golden');

        $this->assertSame('sub_test_golden', $cancellation->subscriptionId);
        $this->assertSame('canceled', $cancellation->status);
        $this->assertSame('customer_requested', $cancellation->reason);
        $this->assertSame('2026-09-04 10:04:00', $cancellation->canceledAt?->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-05 09:55:00', $cancellation->accessEndsAt?->utc()->format('Y-m-d H:i:s'));
    }

    public function test_it_parses_a_real_subscription_reactivate_response(): void
    {
        Http::fake([
            'payments.plorea.no/subscriptions/sub_test_golden/reactivate' => Http::response($this->fixture('subscription-reactivated')),
        ]);

        $subscription = Plorea::subscriptions()->reactivate('sub_test_golden');

        $this->assertTrue($subscription->isActive());
        $this->assertSame('sub_test_golden', $subscription->id);
        $this->assertSame(29900, $subscription->amount?->value);

        // Reactivation schedules the next charge for *now*, so the scheduler
        // charges the card again within seconds. It is not a resume-where-you
        // -left-off operation.
        $this->assertSame('2026-09-04 10:04:00', $subscription->nextChargeAt?->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-04T10:04:00.000Z', $subscription->raw['reactivatedAt']);

        // The reactivate response is a partial projection.
        $this->assertNull($subscription->vatRate);
        $this->assertNull($subscription->createdAt);
    }

    public function test_it_parses_a_real_subscription_charge_payment_status_response(): void
    {
        Http::fake([
            'payments.plorea.no/payments/status/*' => Http::response($this->fixture('payment-status-subscription-charge')),
        ]);

        $status = Plorea::payments()->status('sub_test_golden-chg_test_golden_1');

        // A subscription charge resolves through the ordinary payment status
        // endpoint, so Subscription::$lastPaymentReference is fetchable.
        $this->assertTrue($status->isPaid());
        $this->assertSame('subscription', $status->platform);
        $this->assertSame('GOLDEN-EXT-001', $status->orderId);
        $this->assertSame('TESTCHARGEPSP001', $status->pspReference);
        $this->assertSame(29900, $status->amount?->value);
        $this->assertSame('AUTHORISATION', $status->webhookEventCode);
        $this->assertTrue($status->webhookSuccess);
        $this->assertNull($status->paymentLinkId);
    }

    public function test_it_maps_a_real_not_active_payment_method_update_to_validation_error(): void
    {
        Http::fake([
            'payments.plorea.no/subscriptions/sub_test_golden' => Http::response(
                $this->fixture('subscription-update-payment-method-not-active'),
                400,
            ),
        ]);

        try {
            Plorea::subscriptions()->update('sub_test_golden')->paymentMethod('pm_test_golden_failed')->save();
            $this->fail('Expected ValidationException.');
        } catch (ValidationException $caught) {
            $this->assertSame('Payment method is not active', $caught->getMessage());
            $this->assertSame(400, $caught->status);
            $this->assertSame('failed', $caught->response?->json('status'));
        }
    }

    public function test_it_maps_a_real_not_chargeable_subscription_to_validation_error(): void
    {
        Http::fake([
            'payments.plorea.no/subscriptions/sub_test_golden/charge' => Http::response(
                $this->fixture('subscription-charge-not-chargeable'),
                400,
            ),
        ]);

        // A canceled subscription refuses charges with a 400, NOT the 402
        // that maps to ChargeFailedException — that one is a card decline.
        try {
            Plorea::subscriptions()->charge('sub_test_golden');
            $this->fail('Expected ValidationException.');
        } catch (ValidationException $caught) {
            $this->assertSame('Subscription is not chargeable', $caught->getMessage());
            $this->assertSame('canceled', $caught->response?->json('status'));
        }
    }

    public function test_it_maps_a_real_tenant_mismatch_response_to_an_authentication_error(): void
    {
        Http::fake([
            'payments.plorea.no/payments/status/*' => Http::response($this->fixture('payment-status-tenant-mismatch'), 403),
        ]);

        // Plorea-side bug (reported 2026-09-04): a scheduler-created charge's
        // payment record is not stamped with the creating tenant, so looking
        // it up by reference 403s. Manually created charges resolve fine.
        try {
            Plorea::payments()->status('sub_test_golden-chg_test_golden_2');
            $this->fail('Expected AuthenticationException.');
        } catch (AuthenticationException $caught) {
            $this->assertSame('Tenant mismatch', $caught->getMessage());
            $this->assertSame(403, $caught->status);
        }
    }
}
