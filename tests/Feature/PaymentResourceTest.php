<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MemberFlow\Plorea\Data\Amount;
use MemberFlow\Plorea\Exceptions\AuthenticationException;
use MemberFlow\Plorea\Exceptions\NotFoundException;
use MemberFlow\Plorea\Exceptions\PloreaException;
use MemberFlow\Plorea\Exceptions\RequestException;
use MemberFlow\Plorea\Exceptions\ServerException;
use MemberFlow\Plorea\Exceptions\ValidationException;
use MemberFlow\Plorea\Facades\Plorea;
use MemberFlow\Plorea\Tests\TestCase;

class PaymentResourceTest extends TestCase
{
    public function test_it_creates_a_payment_link(): void
    {
        Http::fake([
            'payments.plorea.no/payments/link' => Http::response([
                'status' => 'created',
                'environment' => 'test',
                'paymentLinkUrl' => 'https://pay.plorea.no/pl_123',
                'paymentLinkId' => 'pl_123',
                'reference' => 'FIN-2026-00123',
                'tenantId' => 'test-tenant',
                'provider' => 'plorea',
                'splitsEnabled' => true,
                'expiresAt' => '2026-10-21T10:00:00Z',
            ]),
        ]);

        $link = Plorea::payments()
            ->link('FIN-2026-00123', 'Faktura FIN-2026-00123', Amount::nok(450000), 'https://debet.no/paid')
            ->payerEmail('kunde@eksempel.no')
            ->invoiceUrl('https://debet.no/invoices/123.pdf')
            ->merchant(orgNr: '912650774', name: 'Techify AS', email: 'post@techify.no')
            ->create();

        $this->assertSame('https://pay.plorea.no/pl_123', $link->url);
        $this->assertSame('pl_123', $link->id);
        $this->assertSame('FIN-2026-00123', $link->reference);
        $this->assertTrue($link->splitsEnabled);
        $this->assertSame('2026-10-21 10:00:00', $link->expiresAt?->format('Y-m-d H:i:s'));

        Http::assertSent(function (Request $request): bool {
            $this->assertSame('https://payments.plorea.no/payments/link', $request->url());
            $this->assertSame('Bearer plr_test_key', $request->header('Authorization')[0]);
            $this->assertSame('test', $request->header('X-Environment')[0]);
            $this->assertSame([
                'tenantId' => 'test-tenant',
                'reference' => 'FIN-2026-00123',
                'product' => 'Faktura FIN-2026-00123',
                'amount' => 450000,
                'currency' => 'NOK',
                'email' => 'kunde@eksempel.no',
                'returnUrl' => 'https://debet.no/paid',
                'invoice_url' => 'https://debet.no/invoices/123.pdf',
                'merchantOrgNr' => '912650774',
                'merchantName' => 'Techify AS',
                'merchantEmail' => 'post@techify.no',
            ], $request->data());

            return true;
        });
    }

    public function test_it_fetches_payment_status(): void
    {
        Http::fake([
            'payments.plorea.no/payments/status/FIN-2026-00123' => Http::response([
                'reference' => 'FIN-2026-00123',
                'status' => 'authorised',
                'pspReference' => 'KZN8ZJVSMQR3JM65',
                'amount' => 450000,
                'currency' => 'NOK',
                'webhookEventCode' => 'AUTHORISATION',
                'webhookSuccess' => true,
            ]),
        ]);

        $status = Plorea::payments()->status('FIN-2026-00123');

        $this->assertTrue($status->isAuthorised());
        $this->assertTrue($status->is('AUTHORISED'));
        $this->assertTrue($status->isPaid());
        $this->assertFalse($status->isOpen());
        $this->assertSame(450000, $status->amount?->value);
        $this->assertSame(4500.0, $status->amount->inMajorUnits());
        $this->assertTrue($status->webhookSuccess);
    }

    public function test_it_requests_a_refund(): void
    {
        Http::fake([
            'payments.plorea.no/payments/refund' => Http::response([
                'status' => 'refund_requested',
                'reference' => 'FIN-2026-00123',
                'modificationReference' => 'FIN-2026-00123-refund-1',
                'refundPspReference' => 'ZQDMC8PSNNJPS865',
                'paymentPspReference' => 'KZN8ZJVSMQR3JM65',
                'amount' => 450000,
                'currency' => 'NOK',
            ]),
        ]);

        $refund = Plorea::payments()->refund(
            'FIN-2026-00123',
            'FIN-2026-00123-refund-1',
            reason: 'Customer requested refund',
        );

        $this->assertSame('refund_requested', $refund->status);
        $this->assertSame(450000, $refund->amount?->value);

        Http::assertSent(fn (Request $request): bool => $request->data() === [
            'reference' => 'FIN-2026-00123',
            'modificationReference' => 'FIN-2026-00123-refund-1',
            'reason' => 'Customer requested refund',
        ]);
    }

    public function test_it_requests_a_cancellation(): void
    {
        Http::fake([
            'payments.plorea.no/payments/cancel' => Http::response([
                'status' => 'cancel_requested',
                'reference' => 'FIN-2026-00123',
                'modificationReference' => 'FIN-2026-00123-cancel-1',
            ]),
        ]);

        $cancellation = Plorea::payments()->cancel('FIN-2026-00123', 'FIN-2026-00123-cancel-1');

        $this->assertSame('cancel_requested', $cancellation->status);
        $this->assertSame('FIN-2026-00123', $cancellation->reference);
    }

    public function test_it_maps_error_responses_to_exceptions(): void
    {
        $expectations = [
            400 => ValidationException::class,
            401 => AuthenticationException::class,
            404 => NotFoundException::class,
            500 => ServerException::class,
        ];

        $sequence = Http::sequence();

        foreach (array_keys($expectations) as $statusCode) {
            $sequence->push(['error' => 'Something failed'], $statusCode);
        }

        Http::fake(['*' => $sequence]);

        foreach ($expectations as $statusCode => $exception) {
            try {
                Plorea::payments()->status('ref');
                $this->fail("Expected {$exception} for status {$statusCode}.");
            } catch (RequestException $caught) {
                $this->assertInstanceOf($exception, $caught);
                $this->assertSame('Something failed', $caught->getMessage());
                $this->assertSame($statusCode, $caught->status);
            }
        }
    }

    public function test_it_falls_back_when_the_error_body_has_an_unexpected_shape(): void
    {
        Http::fake(['*' => Http::response('upstream exploded', 500)]);

        try {
            Plorea::payments()->status('ref');
            $this->fail('Expected ServerException.');
        } catch (ServerException $caught) {
            $this->assertStringContainsString('status 500', $caught->getMessage());
            $this->assertStringContainsString('upstream exploded', $caught->getMessage());
            $this->assertSame('upstream exploded', $caught->response?->body());
        }
    }

    public function test_it_requires_an_api_key(): void
    {
        config()->set('plorea.api_key');

        $this->expectException(PloreaException::class);
        $this->expectExceptionMessage('No Plorea API key is configured');

        Plorea::payments()->status('ref');
    }
}
