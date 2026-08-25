<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Tests\Feature;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use MemberFlow\Plorea\Events\PaymentStatusUpdated;
use MemberFlow\Plorea\Events\WebhookReceived;
use MemberFlow\Plorea\Tests\TestCase;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Symfony\Component\HttpFoundation\Response;

class WebhookTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('plorea.webhooks.secret', 'whsec_test');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return TestResponse<Response>
     */
    protected function postWebhook(array $payload, ?string $authorization = 'whsec_test')
    {
        $headers = $authorization === null ? [] : ['Authorization' => $authorization];

        return $this->postJson('/plorea/webhook', $payload, $headers);
    }

    public function test_it_handles_a_webhook_and_dispatches_events(): void
    {
        Event::fake([WebhookReceived::class, PaymentStatusUpdated::class]);

        $payload = [
            'reference' => 'FIN-2026-00123',
            'status' => 'authorised',
            'pspReference' => 'KZN8ZJVSMQR3JM65',
        ];

        $this->postWebhook($payload)
            ->assertOk()
            ->assertSee('[accepted]');

        Event::assertDispatched(WebhookReceived::class, fn (WebhookReceived $event): bool => $event->payload === $payload);
        Event::assertDispatched(PaymentStatusUpdated::class, fn (PaymentStatusUpdated $event): bool => $event->reference === 'FIN-2026-00123'
            && $event->status === 'authorised');
    }

    public function test_it_reads_the_reference_from_nested_and_alternative_keys(): void
    {
        Event::fake([PaymentStatusUpdated::class]);

        $this->postWebhook(['data' => ['reference' => 'ref-nested']])->assertOk();
        $this->postWebhook(['merchantReference' => 'ref-merchant'])->assertOk();

        Event::assertDispatched(PaymentStatusUpdated::class, fn (PaymentStatusUpdated $event): bool => $event->reference === 'ref-nested');
        Event::assertDispatched(PaymentStatusUpdated::class, fn (PaymentStatusUpdated $event): bool => $event->reference === 'ref-merchant');
    }

    public function test_it_acknowledges_payloads_without_a_reference(): void
    {
        Event::fake([WebhookReceived::class, PaymentStatusUpdated::class]);

        $this->postWebhook(['eventCode' => 'AUTHORISATION'])->assertOk();

        Event::assertDispatched(WebhookReceived::class);
        Event::assertNotDispatched(PaymentStatusUpdated::class);
    }

    public function test_it_accepts_the_secret_with_a_bearer_prefix(): void
    {
        Event::fake([WebhookReceived::class]);

        $this->postWebhook(['reference' => 'ref-1'], 'Bearer whsec_test')->assertOk();

        Event::assertDispatched(WebhookReceived::class);
    }

    public function test_it_rejects_a_wrong_or_missing_secret(): void
    {
        Event::fake([WebhookReceived::class]);

        $this->postWebhook(['reference' => 'ref-1'], 'wrong-secret')->assertForbidden();
        $this->postWebhook(['reference' => 'ref-1'], 'Bearer wrong-secret')->assertForbidden();
        $this->postWebhook(['reference' => 'ref-1'], authorization: null)->assertForbidden();

        Event::assertNotDispatched(WebhookReceived::class);
    }

    public function test_it_fails_closed_when_no_secret_is_configured(): void
    {
        config()->set('plorea.webhooks.secret');

        Event::fake([WebhookReceived::class]);

        $this->postWebhook(['reference' => 'ref-1'], 'anything')->assertForbidden();

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
