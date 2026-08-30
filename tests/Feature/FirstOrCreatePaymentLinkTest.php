<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MemberFlow\Plorea\Data\Amount;
use MemberFlow\Plorea\Enums\Environment;
use MemberFlow\Plorea\Exceptions\PaymentAlreadyPaidException;
use MemberFlow\Plorea\Exceptions\PloreaException;
use MemberFlow\Plorea\Facades\Plorea;
use MemberFlow\Plorea\Pending\PendingPaymentLink;
use MemberFlow\Plorea\Tests\TestCase;

class FirstOrCreatePaymentLinkTest extends TestCase
{
    protected function pending(): PendingPaymentLink
    {
        return Plorea::payments()
            ->link('INV-1', 'Invoice INV-1', Amount::nok(50000), 'https://app.test/paid')
            ->merchant(orgNr: '912650774');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function statusResponse(array $overrides = []): array
    {
        return [
            'reference' => 'INV-1',
            'status' => 'active',
            'amount' => 50000,
            'currency' => 'NOK',
            'environment' => 'test',
            'paymentLinkId' => 'pl_existing',
            'paymentLinkUrl' => 'https://pay.plorea.no/pl_existing',
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function linkResponse(array $overrides = []): array
    {
        return [
            'id' => 'pl_existing',
            'reference' => 'INV-1',
            'amount' => 50000,
            'currency' => 'NOK',
            'environment' => 'test',
            'expired' => false,
            ...$overrides,
        ];
    }

    public function test_it_creates_when_no_payment_exists(): void
    {
        Http::fake([
            'payments.plorea.no/payments/status/INV-1' => Http::response(['error' => 'Not found'], 404),
            'payments.plorea.no/payments/link' => Http::response(['paymentLinkId' => 'pl_new', 'paymentLinkUrl' => 'https://pay.plorea.no/pl_new', 'reference' => 'INV-1', 'status' => 'created']),
        ]);

        $link = $this->pending()->firstOrCreate();

        $this->assertSame('pl_new', $link->id);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && ($request->data()['reference'] ?? null) === 'INV-1');
    }

    public function test_it_reuses_an_open_link_with_the_same_amount(): void
    {
        Http::fake([
            'payments.plorea.no/payments/status/INV-1' => Http::response($this->statusResponse()),
            'payments.plorea.no/pay/pl_existing' => Http::response($this->linkResponse()),
        ]);

        $link = $this->pending()->firstOrCreate();

        $this->assertSame('pl_existing', $link->id);
        $this->assertSame('https://pay.plorea.no/pl_existing', $link->url);

        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    public function test_it_reuses_an_open_link_when_the_expiry_lookup_is_unavailable(): void
    {
        Http::fake([
            'payments.plorea.no/payments/status/INV-1' => Http::response($this->statusResponse()),
            'payments.plorea.no/pay/pl_existing' => Http::response(['error' => 'Unavailable'], 500),
        ]);

        $link = $this->pending()->firstOrCreate();

        $this->assertSame('pl_existing', $link->id);
    }

    public function test_it_throws_when_the_payment_is_already_paid(): void
    {
        Http::fake([
            'payments.plorea.no/payments/status/INV-1' => Http::response($this->statusResponse(['status' => 'paid'])),
        ]);

        try {
            $this->pending()->firstOrCreate();
            $this->fail('Expected PaymentAlreadyPaidException.');
        } catch (PaymentAlreadyPaidException $e) {
            $this->assertSame('INV-1', $e->status->reference);
            $this->assertTrue($e->status->isPaid());
        }
    }

    public function test_an_authorised_payment_also_counts_as_paid(): void
    {
        Http::fake([
            'payments.plorea.no/payments/status/INV-1' => Http::response($this->statusResponse(['status' => 'authorised'])),
        ]);

        $this->expectException(PaymentAlreadyPaidException::class);

        $this->pending()->firstOrCreate();
    }

    public function test_it_suffixes_the_reference_when_the_link_is_dead(): void
    {
        Http::fake([
            'payments.plorea.no/payments/status/INV-1' => Http::response($this->statusResponse(['status' => 'expired'])),
            'payments.plorea.no/payments/status/INV-1-1' => Http::response(['error' => 'Not found'], 404),
            'payments.plorea.no/payments/link' => Http::response(['paymentLinkId' => 'pl_new', 'paymentLinkUrl' => 'https://pay.plorea.no/pl_new', 'reference' => 'INV-1-1', 'status' => 'created']),
        ]);

        $link = $this->pending()->firstOrCreate();

        $this->assertSame('INV-1-1', $link->reference);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && ($request->data()['reference'] ?? null) === 'INV-1-1');
    }

    public function test_it_suffixes_the_reference_when_the_open_link_has_expired(): void
    {
        Http::fake([
            'payments.plorea.no/payments/status/INV-1' => Http::response($this->statusResponse()),
            'payments.plorea.no/pay/pl_existing' => Http::response($this->linkResponse(['expired' => true])),
            'payments.plorea.no/payments/status/INV-1-1' => Http::response(['error' => 'Payment not found'], 404),
            'payments.plorea.no/payments/link' => Http::response(['paymentLinkId' => 'pl_new', 'paymentLinkUrl' => 'https://pay.plorea.no/pl_new', 'reference' => 'INV-1-1', 'status' => 'created']),
        ]);

        $link = $this->pending()->firstOrCreate();

        $this->assertSame('INV-1-1', $link->reference);
    }

    public function test_it_suffixes_the_reference_when_the_expiry_date_has_passed(): void
    {
        Http::fake([
            'payments.plorea.no/payments/status/INV-1' => Http::response($this->statusResponse()),
            'payments.plorea.no/pay/pl_existing' => Http::response($this->linkResponse(['expiresAt' => '2020-01-01T00:00:00Z'])),
            'payments.plorea.no/payments/status/INV-1-1' => Http::response(['error' => 'Payment not found'], 404),
            'payments.plorea.no/payments/link' => Http::response(['paymentLinkId' => 'pl_new', 'paymentLinkUrl' => 'https://pay.plorea.no/pl_new', 'reference' => 'INV-1-1', 'status' => 'created']),
        ]);

        $link = $this->pending()->firstOrCreate();

        $this->assertSame('INV-1-1', $link->reference);
    }

    public function test_it_suffixes_the_reference_when_the_environment_differs(): void
    {
        Http::fake([
            'payments.plorea.no/payments/status/INV-1' => Http::response($this->statusResponse(['environment' => 'live'])),
            'payments.plorea.no/payments/status/INV-1-1' => Http::response(['error' => 'Payment not found'], 404),
            'payments.plorea.no/payments/link' => Http::response(['paymentLinkId' => 'pl_new', 'paymentLinkUrl' => 'https://pay.plorea.no/pl_new', 'reference' => 'INV-1-1', 'status' => 'created']),
        ]);

        $link = $this->pending()->firstOrCreate();

        $this->assertSame('INV-1-1', $link->reference);
    }

    public function test_it_suffixes_the_reference_when_the_amount_differs(): void
    {
        Http::fake([
            'payments.plorea.no/payments/status/INV-1' => Http::response($this->statusResponse(['amount' => 99900])),
            'payments.plorea.no/payments/status/INV-1-1' => Http::response(['error' => 'Not found'], 404),
            'payments.plorea.no/payments/link' => Http::response(['paymentLinkId' => 'pl_new', 'paymentLinkUrl' => 'https://pay.plorea.no/pl_new', 'reference' => 'INV-1-1', 'status' => 'created']),
        ]);

        $link = $this->pending()->firstOrCreate();

        $this->assertSame('pl_new', $link->id);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && ($request->data()['reference'] ?? null) === 'INV-1-1'
            && ($request->data()['amount'] ?? null) === 50000);
    }

    public function test_it_throws_after_exhausting_all_reference_suffixes(): void
    {
        Http::fake([
            'payments.plorea.no/payments/status/INV-1*' => Http::response($this->statusResponse(['environment' => 'live'])),
        ]);

        try {
            $this->pending()->firstOrCreate(maxAttempts: 2);
            $this->fail('Expected PloreaException.');
        } catch (PloreaException $e) {
            $this->assertStringContainsString('Unable to find or create a payment link for [INV-1]', $e->getMessage());
        }

        Http::assertSentCount(3);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    public function test_it_reuses_an_open_link_when_the_expiry_lookup_cannot_connect(): void
    {
        Http::fake([
            'payments.plorea.no/payments/status/INV-1' => Http::response($this->statusResponse()),
            'payments.plorea.no/pay/pl_existing' => fn () => throw new ConnectionException('Connection timed out'),
        ]);

        $link = $this->pending()->firstOrCreate();

        $this->assertSame('pl_existing', $link->id);
    }

    public function test_it_suffixes_the_reference_when_the_stored_link_no_longer_exists(): void
    {
        Http::fake([
            'payments.plorea.no/payments/status/INV-1' => Http::response($this->statusResponse()),
            'payments.plorea.no/pay/pl_existing' => Http::response(['error' => 'Not found'], 404),
            'payments.plorea.no/payments/status/INV-1-1' => Http::response(['error' => 'Payment not found'], 404),
            'payments.plorea.no/payments/link' => Http::response(['paymentLinkId' => 'pl_new', 'paymentLinkUrl' => 'https://pay.plorea.no/pl_new', 'reference' => 'INV-1-1', 'status' => 'created']),
        ]);

        $link = $this->pending()->firstOrCreate();

        $this->assertSame('INV-1-1', $link->reference);
    }

    public function test_it_suffixes_the_reference_when_the_currency_differs(): void
    {
        Http::fake([
            'payments.plorea.no/payments/status/INV-1' => Http::response($this->statusResponse(['currency' => 'EUR'])),
            'payments.plorea.no/payments/status/INV-1-1' => Http::response(['error' => 'Not found'], 404),
            'payments.plorea.no/payments/link' => Http::response(['paymentLinkId' => 'pl_new', 'paymentLinkUrl' => 'https://pay.plorea.no/pl_new', 'reference' => 'INV-1-1', 'status' => 'created']),
        ]);

        $link = $this->pending()->firstOrCreate();

        $this->assertSame('pl_new', $link->id);
    }

    public function test_it_suffixes_the_reference_when_the_tenant_differs(): void
    {
        Http::fake([
            'payments.plorea.no/payments/status/INV-1' => Http::response($this->statusResponse(['tenantId' => 'someone-elses-tenant'])),
            'payments.plorea.no/payments/status/INV-1-1' => Http::response(['error' => 'Not found'], 404),
            'payments.plorea.no/payments/link' => Http::response(['paymentLinkId' => 'pl_new', 'paymentLinkUrl' => 'https://pay.plorea.no/pl_new', 'reference' => 'INV-1-1', 'status' => 'created']),
        ]);

        $link = $this->pending()->firstOrCreate();

        $this->assertSame('pl_new', $link->id);
    }

    public function test_an_enum_environment_still_guards_reuse(): void
    {
        config()->set('plorea.environment', Environment::Live);

        Http::fake([
            'payments.plorea.no/payments/status/INV-1' => Http::response($this->statusResponse(['environment' => 'test'])),
            'payments.plorea.no/payments/status/INV-1-1' => Http::response(['error' => 'Not found'], 404),
            'payments.plorea.no/payments/link' => Http::response(['paymentLinkId' => 'pl_new', 'paymentLinkUrl' => 'https://pay.plorea.no/pl_new', 'reference' => 'INV-1-1', 'status' => 'created']),
        ]);

        $link = $this->pending()->firstOrCreate();

        $this->assertSame('INV-1-1', $link->reference);
    }

    public function test_the_reference_is_restored_after_exhausting_suffixes(): void
    {
        Http::fake([
            'payments.plorea.no/payments/status/INV-1*' => Http::response($this->statusResponse(['environment' => 'live'])),
        ]);

        $pending = $this->pending();

        try {
            $pending->firstOrCreate(maxAttempts: 1);
            $this->fail('Expected PloreaException.');
        } catch (PloreaException) {
            $this->assertSame('INV-1', $pending->toPayload()['reference']);
        }
    }
}
