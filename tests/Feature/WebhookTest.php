<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Tests\Feature;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Event;
use MemberFlow\Plorea\Events\PaymentStatusUpdated;
use MemberFlow\Plorea\Events\WebhookReceived;
use MemberFlow\Plorea\Tests\TestCase;
use Orchestra\Testbench\Attributes\DefineEnvironment;

class WebhookTest extends TestCase
{
    public function test_it_handles_a_webhook_and_dispatches_events(): void
    {
        Event::fake([WebhookReceived::class, PaymentStatusUpdated::class]);

        $payload = [
            'reference' => 'FIN-2026-00123',
            'status' => 'authorised',
            'pspReference' => 'KZN8ZJVSMQR3JM65',
        ];

        $this->postJson('/plorea/webhook', $payload)
            ->assertOk()
            ->assertSee('[accepted]');

        Event::assertDispatched(WebhookReceived::class, fn (WebhookReceived $event): bool => $event->payload === $payload);
        Event::assertDispatched(PaymentStatusUpdated::class, fn (PaymentStatusUpdated $event): bool => $event->reference === 'FIN-2026-00123'
            && $event->status === 'authorised');
    }

    public function test_it_skips_the_status_event_without_a_reference(): void
    {
        Event::fake([WebhookReceived::class, PaymentStatusUpdated::class]);

        $this->postJson('/plorea/webhook', ['eventCode' => 'AUTHORISATION'])->assertOk();

        Event::assertDispatched(WebhookReceived::class);
        Event::assertNotDispatched(PaymentStatusUpdated::class);
    }

    public function test_it_accepts_a_valid_signature(): void
    {
        config()->set('plorea.webhooks.secret', 'whsec_test');

        Event::fake([WebhookReceived::class]);

        $body = json_encode(['reference' => 'ref-1', 'status' => 'authorised']);
        $signature = hash_hmac('sha256', (string) $body, 'whsec_test');

        $this->call('POST', '/plorea/webhook', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PLOREA_SIGNATURE' => $signature,
        ], content: (string) $body)->assertOk();

        Event::assertDispatched(WebhookReceived::class);
    }

    public function test_it_rejects_an_invalid_signature(): void
    {
        config()->set('plorea.webhooks.secret', 'whsec_test');

        Event::fake([WebhookReceived::class]);

        $body = (string) json_encode(['reference' => 'ref-1']);

        $this->call('POST', '/plorea/webhook', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PLOREA_SIGNATURE' => 'not-the-signature',
        ], content: $body)->assertForbidden();

        $this->call('POST', '/plorea/webhook', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: $body)->assertForbidden();

        Event::assertNotDispatched(WebhookReceived::class);
    }

    public function test_the_route_is_registered_with_the_configured_path(): void
    {
        $route = $this->app['router']->getRoutes()->getByName('plorea.webhook');

        $this->assertNotNull($route);
        $this->assertSame('plorea/webhook', $route->uri());
    }

    #[DefineEnvironment('disableWebhooks')]
    public function test_the_route_can_be_disabled(): void
    {
        $this->assertNull($this->app['router']->getRoutes()->getByName('plorea.webhook'));
    }

    protected function disableWebhooks(Application $app): void
    {
        $app['config']->set('plorea.webhooks.enabled', false);
    }
}
