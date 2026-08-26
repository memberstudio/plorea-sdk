<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Tests\Feature;

use Illuminate\Support\Facades\Http;
use MemberFlow\Plorea\Data\Amount;
use MemberFlow\Plorea\Exceptions\NotFoundException;
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
}
