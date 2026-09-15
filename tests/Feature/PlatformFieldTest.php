<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MemberFlow\Plorea\Data\Amount;
use MemberFlow\Plorea\Facades\Plorea;
use MemberFlow\Plorea\Testing\RecordedRequest;
use MemberFlow\Plorea\Tests\TestCase;

/**
 * Plorea tags every request body with the platform that sent it. It is
 * internal reporting on their side with no functional effect (confirmed by
 * Plorea 2026-09-15) — these tests pin where the field is added and, just as
 * importantly, where it is not.
 */
class PlatformFieldTest extends TestCase
{
    public function test_it_adds_the_configured_platform_to_every_request_body(): void
    {
        Http::fake(['payments.plorea.no/*' => Http::response(['status' => 'refund_requested'])]);

        Plorea::payments()->refund('FIN-1', 'FIN-1-refund-1');

        Http::assertSent(fn (Request $request): bool => $request->data()['platform'] === 'memberflow');
    }

    public function test_a_payload_that_already_names_a_platform_keeps_its_own(): void
    {
        Http::fake(['payments.plorea.no/*' => Http::response(['paymentLinkId' => 'pl_1'])]);

        Plorea::payments()
            ->link('FIN-1', 'Faktura', Amount::nok(45000), 'https://app.test/paid')
            ->merchant(orgNr: '912650774')
            ->platform('partner-portal')
            ->create();

        Http::assertSent(fn (Request $request): bool => $request->data()['platform'] === 'partner-portal');
    }

    public function test_an_empty_configured_platform_omits_the_field(): void
    {
        config()->set('plorea.platform', '');

        Http::fake(['payments.plorea.no/*' => Http::response(['status' => 'cancel_requested'])]);

        Plorea::payments()->cancel('FIN-1', 'FIN-1-cancel-1');

        Http::assertSent(fn (Request $request): bool => ! array_key_exists('platform', $request->data()));
    }

    public function test_the_fake_stamps_the_platform_exactly_as_the_client_does(): void
    {
        Plorea::fake();

        Plorea::payments()->refund('FIN-1', 'FIN-1-refund-1');

        Plorea::assertSent(
            fn (RecordedRequest $request): bool => $request->input('platform') === 'memberflow',
        );
    }

    /**
     * A GET carries no body. Appending the field to the query string would
     * rewrite the URL of every read endpoint for a value Plorea only ever
     * described as a body field — so reads are left exactly as they were.
     */
    /**
     * A read has no body to carry the field, so it rides in the query string.
     * This changes the URL of every read the SDK makes — pinned here because
     * a consuming app asserting exact GET URLs will see it.
     */
    public function test_it_adds_the_configured_platform_to_the_query_string_of_a_read(): void
    {
        Http::fake(['payments.plorea.no/*' => Http::response(['reference' => 'FIN-1', 'status' => 'active'])]);

        Plorea::payments()->status('FIN-1');

        Http::assertSent(
            fn (Request $request): bool => $request->url() === 'https://payments.plorea.no/payments/status/FIN-1?platform=memberflow',
        );
    }

    public function test_an_unset_platform_leaves_a_read_url_untouched(): void
    {
        config(['plorea.platform' => null]);

        Http::fake(['payments.plorea.no/*' => Http::response(['reference' => 'FIN-1', 'status' => 'active'])]);

        Plorea::payments()->status('FIN-1');

        Http::assertSent(
            fn (Request $request): bool => $request->url() === 'https://payments.plorea.no/payments/status/FIN-1',
        );
    }

    public function test_a_read_keeps_its_own_query_alongside_the_platform(): void
    {
        Http::fake(['payments.plorea.no/*' => Http::response(['count' => 0, 'items' => []])]);

        Plorea::subscriptions()->forExternalId('ws_1');

        Http::assertSent(
            fn (Request $request): bool => $request->url() === 'https://payments.plorea.no/subscriptions?externalId=ws_1&platform=memberflow',
        );
    }
}
