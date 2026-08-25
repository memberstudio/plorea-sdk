<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MemberFlow\Plorea\Facades\Plorea;
use MemberFlow\Plorea\Tests\TestCase;

class PayByLinkResourceTest extends TestCase
{
    public function test_it_finds_a_payment_link(): void
    {
        Http::fake([
            'payments.plorea.no/pay/pl_123' => Http::response([
                'id' => 'pl_123',
                'tenantId' => 'test-tenant',
                'reference' => 'FIN-2026-00123',
                'product' => 'Faktura FIN-2026-00123',
                'amount' => 450000,
                'currency' => 'NOK',
                'invoice_url' => 'https://debet.no/invoices/123.pdf',
                'expired' => false,
            ]),
        ]);

        $link = Plorea::payByLink()->find('pl_123');

        $this->assertSame('pl_123', $link->id);
        $this->assertSame('Faktura FIN-2026-00123', $link->product);
        $this->assertSame(450000, $link->amount?->value);
        $this->assertSame('https://debet.no/invoices/123.pdf', $link->invoiceUrl);
        $this->assertFalse($link->expired);
    }

    public function test_it_creates_a_payment_session(): void
    {
        Http::fake([
            'payments.plorea.no/payments/session' => Http::response([
                'sessionId' => 'CS123',
                'sessionData' => 'AbData',
                'environment' => 'test',
                'clientKey' => 'test_CLIENT_KEY',
            ]),
        ]);

        $session = Plorea::payByLink()->session('pl_123', 'https://pay.plorea.no/pl_123/return');

        $this->assertSame('CS123', $session->sessionId);
        $this->assertSame('AbData', $session->sessionData);
        $this->assertSame('test_CLIENT_KEY', $session->clientKey);

        Http::assertSent(fn (Request $request): bool => $request->data() === [
            'paymentLinkId' => 'pl_123',
            'returnUrl' => 'https://pay.plorea.no/pl_123/return',
        ]);
    }
}
