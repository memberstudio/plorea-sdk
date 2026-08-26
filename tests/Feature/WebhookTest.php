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
     * The X-Plorea-Signature value for a payload: a base64-encoded
     * HMAC-SHA256 of the JSON body, matching how postJson() encodes it.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function signatureFor(array $payload, string $secret = 'whsec_test'): string
    {
        return base64_encode(hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), $secret, true));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return TestResponse<Response>
     */
    protected function postSignedWebhook(array $payload, ?string $signature = null)
    {
        return $this->postJson('/plorea/webhook', $payload, [
            'X-Plorea-Signature' => $signature ?? $this->signatureFor($payload),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return TestResponse<Response>
     */
    protected function postEchoWebhook(array $payload, ?string $authorization = 'whsec_test')
    {
        $headers = $authorization === null ? [] : ['Authorization' => $authorization];

        return $this->postJson('/plorea/webhook', $payload, $headers);
    }

    public function test_it_handles_a_real_signed_webhook_and_dispatches_events(): void
    {
        Event::fake([WebhookReceived::class, PaymentStatusUpdated::class]);

        $payload = $this->fixture('webhook-payment-authorised');

        $this->postSignedWebhook($payload)
            ->assertOk()
            ->assertSee('[accepted]');

        Event::assertDispatched(WebhookReceived::class, fn (WebhookReceived $event): bool => $event->payload === $payload);
        Event::assertDispatched(PaymentStatusUpdated::class, fn (PaymentStatusUpdated $event): bool => $event->reference === 'GOLDEN-2026-001'
            && $event->status === 'authorised');
    }

    public function test_it_rejects_a_wrong_signature(): void
    {
        Event::fake([WebhookReceived::class]);

        $payload = $this->fixture('webhook-payment-authorised');

        $this->postSignedWebhook($payload, $this->signatureFor($payload, 'whsec_other'))->assertForbidden();
        $this->postSignedWebhook($payload, 'not-a-signature')->assertForbidden();

        Event::assertNotDispatched(WebhookReceived::class);
    }

    public function test_the_signature_wins_over_the_authorization_header_when_both_are_present(): void
    {
        Event::fake([WebhookReceived::class]);

        // A correct echoed secret must not rescue a request whose signature is wrong.
        $this->postJson('/plorea/webhook', ['reference' => 'ref-1'], [
            'X-Plorea-Signature' => 'bm90LXRoZS1yaWdodC1zaWduYXR1cmU=',
            'Authorization' => 'whsec_test',
        ])->assertForbidden();

        Event::assertNotDispatched(WebhookReceived::class);
    }

    public function test_it_reads_the_reference_from_nested_and_alternative_keys(): void
    {
        Event::fake([PaymentStatusUpdated::class]);

        $this->postSignedWebhook(['data' => ['reference' => 'ref-nested']])->assertOk();
        $this->postSignedWebhook(['merchantReference' => 'ref-merchant'])->assertOk();

        Event::assertDispatched(PaymentStatusUpdated::class, fn (PaymentStatusUpdated $event): bool => $event->reference === 'ref-nested');
        Event::assertDispatched(PaymentStatusUpdated::class, fn (PaymentStatusUpdated $event): bool => $event->reference === 'ref-merchant');
    }

    public function test_it_reads_the_status_from_nested_and_alternative_keys(): void
    {
        Event::fake([PaymentStatusUpdated::class]);

        $this->postSignedWebhook(['reference' => 'ref-1', 'data' => ['status' => 'authorised']])->assertOk();
        $this->postSignedWebhook(['reference' => 'ref-2', 'data' => ['eventCode' => 'AUTHORISATION']])->assertOk();
        $this->postSignedWebhook(['reference' => 'ref-3', 'type' => 'payment.authorised'])->assertOk();

        Event::assertDispatched(PaymentStatusUpdated::class, fn (PaymentStatusUpdated $event): bool => $event->reference === 'ref-1'
            && $event->status === 'authorised');
        Event::assertDispatched(PaymentStatusUpdated::class, fn (PaymentStatusUpdated $event): bool => $event->reference === 'ref-2'
            && $event->status === 'AUTHORISATION');
        Event::assertDispatched(PaymentStatusUpdated::class, fn (PaymentStatusUpdated $event): bool => $event->reference === 'ref-3'
            && $event->status === 'payment.authorised');
    }

    public function test_it_acknowledges_payloads_without_a_reference(): void
    {
        Event::fake([WebhookReceived::class, PaymentStatusUpdated::class]);

        $this->postSignedWebhook(['eventCode' => 'AUTHORISATION'])->assertOk();

        Event::assertDispatched(WebhookReceived::class);
        Event::assertNotDispatched(PaymentStatusUpdated::class);
    }

    public function test_it_accepts_an_unsigned_request_with_the_echoed_secret(): void
    {
        Event::fake([WebhookReceived::class]);

        $this->postEchoWebhook(['reference' => 'ref-1'])->assertOk();
        $this->postEchoWebhook(['reference' => 'ref-2'], 'Bearer whsec_test')->assertOk();
        $this->postEchoWebhook(['reference' => 'ref-3'], 'bearer whsec_test')->assertOk();

        Event::assertDispatchedTimes(WebhookReceived::class, 3);
    }

    public function test_it_rejects_a_wrong_or_missing_secret_without_a_signature(): void
    {
        Event::fake([WebhookReceived::class]);

        $this->postEchoWebhook(['reference' => 'ref-1'], 'wrong-secret')->assertForbidden();
        $this->postEchoWebhook(['reference' => 'ref-1'], 'Bearer wrong-secret')->assertForbidden();
        $this->postEchoWebhook(['reference' => 'ref-1'], authorization: null)->assertForbidden();

        Event::assertNotDispatched(WebhookReceived::class);
    }

    public function test_it_skips_authentication_when_verification_is_disabled(): void
    {
        config()->set('plorea.webhooks.verify', false);
        config()->set('plorea.webhooks.secret');

        Event::fake([WebhookReceived::class, PaymentStatusUpdated::class]);

        $payload = $this->fixture('webhook-payment-authorised');

        // No secret configured, no signature, no Authorization — still accepted.
        $this->postJson('/plorea/webhook', $payload)->assertOk();

        Event::assertDispatched(WebhookReceived::class);
        Event::assertDispatched(PaymentStatusUpdated::class, fn (PaymentStatusUpdated $event): bool => $event->reference === 'GOLDEN-2026-001');
    }

    public function test_it_still_verifies_by_default_when_the_verify_option_is_absent(): void
    {
        config()->offsetUnset('plorea.webhooks.verify');

        Event::fake([WebhookReceived::class]);

        $this->postJson('/plorea/webhook', ['reference' => 'ref-1'])->assertForbidden();

        Event::assertNotDispatched(WebhookReceived::class);
    }

    public function test_it_fails_closed_when_no_secret_is_configured(): void
    {
        config()->set('plorea.webhooks.secret');

        Event::fake([WebhookReceived::class]);

        $payload = ['reference' => 'ref-1'];

        $this->postSignedWebhook($payload, $this->signatureFor($payload, ''))->assertForbidden();
        $this->postEchoWebhook($payload, 'anything')->assertForbidden();

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
