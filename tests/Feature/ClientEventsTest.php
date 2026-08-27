<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use MemberFlow\Plorea\Data\Amount;
use MemberFlow\Plorea\Events\RequestSent;
use MemberFlow\Plorea\Events\ResponseReceived;
use MemberFlow\Plorea\Exceptions\NotFoundException;
use MemberFlow\Plorea\Facades\Plorea;
use MemberFlow\Plorea\Tests\TestCase;

class ClientEventsTest extends TestCase
{
    public function test_it_dispatches_request_and_response_events_for_successful_requests(): void
    {
        Event::fake([RequestSent::class, ResponseReceived::class]);

        Http::fake([
            'payments.plorea.no/payments/link' => Http::response([
                'status' => 'created',
                'paymentLinkUrl' => 'https://pay.plorea.no/pl_123',
                'paymentLinkId' => 'pl_123',
                'reference' => 'FIN-2026-00123',
            ]),
        ]);

        Plorea::payments()
            ->link('FIN-2026-00123', 'Faktura FIN-2026-00123', Amount::nok(450000), 'https://app.example/paid')
            ->create();

        Event::assertDispatched(RequestSent::class, function (RequestSent $event): bool {
            $this->assertSame('POST', $event->method);
            $this->assertSame('payments/link', $event->uri);
            $this->assertSame(450000, $event->payload['amount']);
            $this->assertArrayNotHasKey('Authorization', $event->payload);

            return true;
        });

        Event::assertDispatched(ResponseReceived::class, function (ResponseReceived $event): bool {
            $this->assertSame('POST', $event->method);
            $this->assertSame('payments/link', $event->uri);
            $this->assertSame(450000, $event->payload['amount']);
            $this->assertSame(200, $event->status);
            $this->assertSame('pl_123', $event->response['paymentLinkId'] ?? null);
            $this->assertGreaterThanOrEqual(0, $event->durationMs);

            return true;
        });
    }

    public function test_it_dispatches_the_response_event_before_throwing_on_error_responses(): void
    {
        Event::fake([RequestSent::class, ResponseReceived::class]);

        Http::fake([
            'payments.plorea.no/payments/status/unknown' => Http::response(['error' => 'Not found'], 404),
        ]);

        try {
            Plorea::payments()->status('unknown');
            $this->fail('Expected a NotFoundException.');
        } catch (NotFoundException) {
            // expected
        }

        Event::assertDispatched(ResponseReceived::class, function (ResponseReceived $event): bool {
            $this->assertSame('GET', $event->method);
            $this->assertSame(404, $event->status);
            $this->assertSame(['error' => 'Not found'], $event->response);

            return true;
        });
    }
}
