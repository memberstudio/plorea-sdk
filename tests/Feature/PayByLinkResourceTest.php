<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MemberFlow\Plorea\Exceptions\PloreaException;
use MemberFlow\Plorea\Facades\Plorea;
use MemberFlow\Plorea\Tests\TestCase;

class PayByLinkResourceTest extends TestCase
{
    public function test_it_finds_a_payment_link(): void
    {
        Http::fake([
            'payments.plorea.no/pay/pl_123?*' => Http::response([
                'id' => 'pl_123',
                'tenantId' => 'test-tenant',
                'reference' => 'FIN-2026-00123',
                'product' => 'Faktura FIN-2026-00123',
                'amount' => 450000,
                'currency' => 'NOK',
                'invoice_url' => 'https://app.example/invoices/123.pdf',
                'expired' => false,
            ]),
        ]);

        $link = Plorea::payByLink()->find('pl_123');

        $this->assertSame('pl_123', $link->id);
        $this->assertSame('Faktura FIN-2026-00123', $link->product);
        $this->assertSame(450000, $link->amount?->value);
        $this->assertSame('https://app.example/invoices/123.pdf', $link->invoiceUrl);
        $this->assertFalse($link->expired);
    }

    public function test_it_reads_the_alternative_link_field_names(): void
    {
        // Plorea's pay page accepts these names too (2026-10-10).
        Http::fake([
            'payments.plorea.no/pay/pl_123?*' => Http::response([
                'id' => 'pl_123',
                'orgNr' => '123456789',
                'invoiceUrl' => 'https://app.example/invoices/123.pdf',
                'status' => 'expired',
            ]),
        ]);

        $link = Plorea::payByLink()->find('pl_123');

        $this->assertSame('123456789', $link->merchantOrgNr);
        $this->assertSame('https://app.example/invoices/123.pdf', $link->invoiceUrl);
        $this->assertTrue($link->expired);
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
            'platform' => 'memberflow',
        ]);
    }

    public function test_it_reads_adyens_own_session_field_names(): void
    {
        // Plorea's pay page falls back to Adyen's id/data (2026-09-14).
        Http::fake([
            'payments.plorea.no/payments/session' => Http::response([
                'id' => 'CS123',
                'data' => 'AbData',
                'environment' => 'test',
                'clientKey' => 'test_CLIENT_KEY',
            ]),
        ]);

        $session = Plorea::payByLink()->session('pl_123');

        $this->assertSame('CS123', $session->sessionId);
        $this->assertSame('AbData', $session->sessionData);
    }

    public function test_a_session_without_an_id_throws(): void
    {
        Http::fake([
            'payments.plorea.no/payments/session' => Http::response([
                'sessionData' => 'AbData',
                'environment' => 'test',
            ]),
        ]);

        $this->expectException(PloreaException::class);
        $this->expectExceptionMessage('without a session id');

        Plorea::payByLink()->session('pl_123');
    }
}
