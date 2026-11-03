<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Tests\Feature\Mcp;

use Illuminate\Auth\GenericUser;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Routing\Route;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use MemberFlow\Plorea\Contracts\Client;
use MemberFlow\Plorea\Events\PaymentStatusUpdated;
use MemberFlow\Plorea\Events\SubscriptionChargeFailed;
use MemberFlow\Plorea\Events\SubscriptionChargeSucceeded;
use MemberFlow\Plorea\Events\WebhookReceived;
use MemberFlow\Plorea\Http\Middleware\AuthenticateWebhook;
use MemberFlow\Plorea\Mcp\PloreaServer;
use MemberFlow\Plorea\Mcp\Tools\SendTestWebhook;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\VarDumper\VarDumper;

class SendTestWebhookTest extends McpTestCase
{
    public $terminatingCallbacks;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('plorea.webhooks.secret', 'whsec_test');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function templates(): array
    {
        return [
            'payment.authorised' => ['payment.authorised', 'webhook-payment-authorised'],
            'payment.failed' => ['payment.failed', 'webhook-payment-failed'],
            'subscription.charge_succeeded' => ['subscription.charge_succeeded', 'webhook-subscription-charge-succeeded'],
            'subscription.charge_failed' => ['subscription.charge_failed', 'webhook-subscription-charge-failed'],
        ];
    }

    /**
     * A template must have the exact shape of a captured delivery, so the
     * test event exercises the same code a real one does.
     */
    #[DataProvider('templates')]
    public function test_each_template_matches_its_captured_delivery(string $type, string $fixture): void
    {
        $template = json_decode((string) file_get_contents(__DIR__."/../../../resources/webhooks/{$type}.json"), true, flags: JSON_THROW_ON_ERROR);
        $captured = $this->fixture($fixture);

        $this->assertSame(array_keys($captured), array_keys($template));
        $this->assertSame(array_keys($captured['data']), array_keys($template['data']));
        $this->assertSame($type, $template['type']);
        $this->assertSame('test', $template['data']['environment']);
    }

    public function test_a_payment_event_passes_the_real_signature_check_and_dispatches(): void
    {
        Event::fake();

        $result = $this->callTool(SendTestWebhook::class, ['type' => 'payment.authorised', 'reference' => 'ORDER-42']);

        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['accepted']);
        $this->assertTrue($result['signed']);
        $this->assertSame('ORDER-42', $result['reference']);
        $this->assertMatchesRegularExpression('/^evt_[0-9a-f]{32}$/', $result['eventId']);
        $this->assertSame([WebhookReceived::class, PaymentStatusUpdated::class], array_column($result['events'], 'event'));

        Event::assertDispatched(PaymentStatusUpdated::class, fn (PaymentStatusUpdated $event): bool => $event->reference === 'ORDER-42'
            && $event->status === 'authorised'
            && $event->eventId === $result['eventId']);
    }

    public function test_a_subscription_event_builds_the_reference_from_its_ids(): void
    {
        Event::fake();

        $result = $this->callTool(SendTestWebhook::class, ['type' => 'subscription.charge_failed', 'subscription_id' => 'sub_1', 'charge_id' => 'chg_2', 'external_id' => 'MEMBER-7']);

        $this->assertSame(200, $result['status']);
        $this->assertSame('sub_1-chg_2', $result['reference']);

        Event::assertDispatched(SubscriptionChargeFailed::class, fn (SubscriptionChargeFailed $event): bool => $event->subscriptionId === 'sub_1'
            && $event->chargeId === 'chg_2'
            && $event->externalId === 'MEMBER-7'
            && $event->retryCount === 1);
        Event::assertNotDispatched(PaymentStatusUpdated::class);
    }

    public function test_a_charge_success_dispatches_the_subscription_event_only(): void
    {
        Event::fake();

        $this->callTool(SendTestWebhook::class, ['type' => 'subscription.charge_succeeded']);

        Event::assertDispatched(SubscriptionChargeSucceeded::class);
        Event::assertNotDispatched(PaymentStatusUpdated::class);
    }

    public function test_a_wrong_secret_reports_the_403_from_the_route(): void
    {
        Event::fake();
        $this->app->instance(AuthenticateWebhook::class, new AuthenticateWebhook(new Repository(['plorea' => ['webhooks' => ['secret' => 'whsec_other']]])));

        $result = $this->callTool(SendTestWebhook::class, ['type' => 'payment.authorised']);

        $this->assertSame(403, $result['status']);
        $this->assertFalse($result['accepted']);
        $this->assertStringContainsString('PLOREA_WEBHOOK_SECRET', $result['detail']);
        Event::assertNotDispatched(WebhookReceived::class);
    }

    public function test_a_throwing_listener_is_a_500_with_a_hint(): void
    {
        Event::listen(PaymentStatusUpdated::class, fn (): never => throw new RuntimeException('listener broke'));

        $result = $this->callTool(SendTestWebhook::class, ['type' => 'payment.failed']);

        $this->assertSame(500, $result['status']);
        $this->assertStringContainsString('app log', $result['detail']);
        $this->assertTrue($result['events'][1]['has_listeners']);
    }

    public function test_the_answer_never_holds_the_secret_or_the_signature(): void
    {
        Event::fake([WebhookReceived::class, PaymentStatusUpdated::class]);
        $signatures = [];
        Event::listen(RequestHandled::class, function (RequestHandled $handled) use (&$signatures): void {
            $signatures[] = (string) $handled->request->header('X-Plorea-Signature');
        });

        $response = PloreaServer::tool(SendTestWebhook::class, ['type' => 'payment.authorised'])
            ->assertOk()
            ->assertDontSee('whsec_test');

        $this->assertCount(1, $signatures);
        $this->assertNotSame('', $signatures[0]);
        $response->assertDontSee($signatures[0]);
    }

    public function test_without_verification_it_sends_unsigned(): void
    {
        Event::fake();
        config()->set('plorea.webhooks.verify', false);
        config()->set('plorea.webhooks.secret');

        $result = $this->callTool(SendTestWebhook::class, ['type' => 'payment.authorised']);

        $this->assertFalse($result['signed']);
        $this->assertSame(200, $result['status']);
    }

    public function test_listener_output_is_discarded_and_reported(): void
    {
        Event::listen(PaymentStatusUpdated::class, function (): void {
            echo request()->header('X-Plorea-Signature');
        });
        $level = ob_get_level();

        $result = $this->callTool(SendTestWebhook::class, ['type' => 'payment.authorised']);

        $this->assertTrue($result['output_discarded']);
        $this->assertSame($level, ob_get_level());
        $this->expectOutputString('');
    }

    public function test_after_response_work_runs_before_the_answer_and_only_once(): void
    {
        $runs = ['before' => 0, 'listener' => 0];
        $this->app->terminating(function () use (&$runs): void {
            $runs['before']++;
        });
        Event::listen(PaymentStatusUpdated::class, function () use (&$runs): void {
            app()->terminating(function () use (&$runs): void {
                $runs['listener']++;
            });
        });

        $this->callTool(SendTestWebhook::class, ['type' => 'payment.authorised']);

        $this->assertSame(['before' => 0, 'listener' => 1], $runs);

        $this->app->terminate();

        $this->assertSame(['before' => 1, 'listener' => 1], $runs);
    }

    public function test_the_router_and_the_auth_guards_are_reset(): void
    {
        Event::fake();
        $guard = $this->app->make('auth')->guard();
        $router = $this->app->make('router');
        $route = $router->current();

        $this->callTool(SendTestWebhook::class, ['type' => 'payment.authorised']);

        $this->assertSame($route, $router->current());
        $this->assertNotSame($guard, $this->app->make('auth')->guard());
    }

    public function test_a_dump_in_a_listener_is_discarded_and_the_dumper_is_restored(): void
    {
        $dumps = [];
        $previous = VarDumper::setHandler(function (mixed $value) use (&$dumps): void {
            $dumps[] = $value;
        });

        try {
            Event::listen(PaymentStatusUpdated::class, function (): void {
                dump(request()->header('X-Plorea-Signature'));
            });

            $result = $this->callTool(SendTestWebhook::class, ['type' => 'payment.authorised']);

            $this->assertTrue($result['output_discarded']);
            $this->assertSame([], $dumps);

            dump('after');

            $this->assertSame(['after'], $dumps);
        } finally {
            VarDumper::setHandler($previous);
        }
    }

    public function test_earlier_deferred_work_is_kept_and_the_delivery_runs_its_own(): void
    {
        $runs = ['before' => 0, 'listener' => 0];
        $deferred = $this->app->make(DeferredCallbackCollection::class);
        defer(function () use (&$runs): void {
            $runs['before']++;
        });
        Event::listen(PaymentStatusUpdated::class, function () use (&$runs): void {
            defer(function () use (&$runs): void {
                $runs['listener']++;
            });
        });

        $this->callTool(SendTestWebhook::class, ['type' => 'payment.authorised']);

        $this->assertSame(['before' => 0, 'listener' => 1], $runs);
        $this->assertSame($deferred, $this->app->make(DeferredCallbackCollection::class));
        $this->assertCount(1, $deferred);
    }

    public function test_the_default_auth_guard_is_restored(): void
    {
        Event::listen(PaymentStatusUpdated::class, function (): void {
            auth()->shouldUse('mcp-test');
        });
        $guard = config('auth.defaults.guard');

        $this->callTool(SendTestWebhook::class, ['type' => 'payment.authorised']);

        $this->assertSame($guard, config('auth.defaults.guard'));
    }

    public function test_the_route_binding_is_removed_when_there_was_none(): void
    {
        Event::fake();
        $this->assertFalse($this->app->bound(Route::class));

        $this->callTool(SendTestWebhook::class, ['type' => 'payment.authorised']);

        $this->assertFalse($this->app->bound(Route::class));
    }

    public function test_an_earlier_route_binding_is_put_back(): void
    {
        Event::fake();
        $route = new Route('GET', 'mcp-test', fn (): string => 'ok');
        $this->app->instance(Route::class, $route);

        $this->callTool(SendTestWebhook::class, ['type' => 'payment.authorised']);

        $this->assertSame($route, $this->app->make(Route::class));
    }

    public function test_it_matches_the_route_when_the_app_lives_in_a_subdirectory(): void
    {
        Event::fake();
        url()->forceRootUrl('https://example.test/subdir');

        $result = $this->callTool(SendTestWebhook::class, ['type' => 'payment.authorised']);

        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['accepted']);
    }

    public function test_a_throw_after_the_response_drops_the_delivery_callbacks(): void
    {
        $runs = ['before' => 0, 'listener' => 0];
        $this->app->terminating(function () use (&$runs): void {
            $runs['before']++;
        });
        Event::listen(PaymentStatusUpdated::class, function () use (&$runs): void {
            app()->terminating(function () use (&$runs): void {
                $runs['listener']++;
            });
        });
        Event::listen(RequestHandled::class, function (): never {
            throw new RuntimeException('audit storage down');
        });
        $count = (fn (): int => count($this->terminatingCallbacks))->call($this->app);

        PloreaServer::tool(SendTestWebhook::class, ['type' => 'payment.authorised'])
            ->assertHasErrors(['The delivery failed before the app answered: RuntimeException']);

        $this->assertSame($count, (fn (): int => count($this->terminatingCallbacks))->call($this->app));

        $this->app->terminate();

        $this->assertSame(['before' => 1, 'listener' => 0], $runs);
    }

    public function test_an_injected_guard_does_not_keep_the_user_of_the_last_delivery(): void
    {
        $seen = [];
        Event::listen(WebhookReceived::class, function (WebhookReceived $event): void {
            auth()->setUser(new GenericUser(['id' => $event->payload['data']['reference']]));
        });
        Event::listen(PaymentStatusUpdated::class, function () use (&$seen): void {
            $seen[] = app(Guard::class)->id();
        });

        $this->callTool(SendTestWebhook::class, ['type' => 'payment.authorised', 'reference' => 'USER-1']);
        $this->callTool(SendTestWebhook::class, ['type' => 'payment.authorised', 'reference' => 'USER-2']);

        $this->assertSame(['USER-1', 'USER-2'], $seen);
    }

    public function test_each_delivery_gets_fresh_scoped_instances_and_the_outer_ones_come_back(): void
    {
        $this->app->scoped('mcp-test.order', fn (): object => (object) ['reference' => request()->json('data.reference')]);
        Context::add('outer', 'kept');
        $outer = $this->app->make('mcp-test.order');
        $seen = [];
        Event::listen(PaymentStatusUpdated::class, function () use (&$seen): void {
            $seen[] = [app('mcp-test.order')->reference, Context::get('outer'), Context::get('order')];
            Context::add('order', app('mcp-test.order')->reference);
        });

        $this->callTool(SendTestWebhook::class, ['type' => 'payment.authorised', 'reference' => 'ORDER-1']);
        $this->callTool(SendTestWebhook::class, ['type' => 'payment.authorised', 'reference' => 'ORDER-2']);

        $this->assertSame([['ORDER-1', null, null], ['ORDER-2', null, null]], $seen);
        $this->assertSame($outer, $this->app->make('mcp-test.order'));
        $this->assertSame('kept', Context::get('outer'));
        $this->assertNull(Context::get('order'));
    }

    public function test_a_scoped_binding_registered_with_a_closure_is_reset_too(): void
    {
        $this->app->scoped(fn (): GenericUser => new GenericUser(['id' => request()->json('data.reference')]));
        $outer = $this->app->make(GenericUser::class);
        $seen = [];
        Event::listen(PaymentStatusUpdated::class, function () use (&$seen): void {
            $seen[] = app(GenericUser::class)->getAuthIdentifier();
        });

        $this->callTool(SendTestWebhook::class, ['type' => 'payment.authorised', 'reference' => 'ORDER-1']);
        $result = $this->callTool(SendTestWebhook::class, ['type' => 'payment.authorised', 'reference' => 'ORDER-2']);

        $this->assertSame(200, $result['status']);
        $this->assertSame(['ORDER-1', 'ORDER-2'], $seen);
        $this->assertSame($outer, $this->app->make(GenericUser::class));
    }

    public function test_each_delivery_gets_a_fresh_session_and_the_outer_one_comes_back(): void
    {
        $this->app->make('router')->getRoutes()->getByName('plorea.webhook')?->middleware(StartSession::class);
        session(['outer' => 'kept']);
        $outer = $this->app->make('session.store');
        $seen = [];
        Event::listen(PaymentStatusUpdated::class, function (PaymentStatusUpdated $event) use (&$seen): void {
            $seen[] = [session('outer'), session('order')];
            session(['order' => $event->reference]);
        });

        $this->callTool(SendTestWebhook::class, ['type' => 'payment.authorised', 'reference' => 'ORDER-1']);
        $result = $this->callTool(SendTestWebhook::class, ['type' => 'payment.authorised', 'reference' => 'ORDER-2']);

        $this->assertSame(200, $result['status']);
        $this->assertSame([[null, null], [null, null]], $seen);
        $this->assertSame($outer, $this->app->make('session.store'));
        $this->assertSame('kept', session('outer'));
        $this->assertNull(session('order'));
    }

    public function test_a_custom_session_driver_still_works_during_and_after_a_delivery(): void
    {
        Session::extend('mcp-test', fn (): ArraySessionHandler => new ArraySessionHandler(120));
        config()->set('session.driver', 'mcp-test');
        $this->app->make('router')->getRoutes()->getByName('plorea.webhook')?->middleware(StartSession::class);
        $seen = [];
        Event::listen(PaymentStatusUpdated::class, function () use (&$seen): void {
            $seen[] = session()->getHandler()::class;
        });

        $result = $this->callTool(SendTestWebhook::class, ['type' => 'payment.authorised']);

        $this->assertSame(200, $result['status']);
        $this->assertSame([ArraySessionHandler::class], $seen);
        $this->assertInstanceOf(ArraySessionHandler::class, Session::driver('mcp-test')->getHandler());
    }

    public function test_a_403_from_a_listener_is_not_called_a_bad_signature(): void
    {
        Event::listen(PaymentStatusUpdated::class, fn (): never => abort(403));

        $result = $this->callTool(SendTestWebhook::class, ['type' => 'payment.authorised']);

        $this->assertSame(403, $result['status']);
        $this->assertStringContainsString('A listener that aborts with 403', $result['detail']);
    }

    public function test_it_is_marked_destructive(): void
    {
        $annotations = $this->app->make(SendTestWebhook::class)->annotations();

        $this->assertTrue($annotations['destructiveHint'] ?? null);
    }

    public function test_the_previous_request_is_restored(): void
    {
        Event::fake();
        $before = $this->app->make('request');

        $this->callTool(SendTestWebhook::class, ['type' => 'payment.authorised']);

        $this->assertSame($before, $this->app->make('request'));
        $this->assertSame($before, request());
    }

    /**
     * @return array<string, array{callable(): void, string}>
     */
    public static function refusals(): array
    {
        return [
            'live environment' => [fn () => config()->set('plorea.environment', 'live'), 'points at live'],
            'live key' => [fn () => config()->set('plorea.api_key', 'plr_live_FAKE'), 'points at live'],
            'invalid environment' => [fn () => config()->set('plorea.environment', 'Test'), 'PLOREA_ENVIRONMENT'],
            'production' => [fn () => app()->detectEnvironment(fn (): string => 'production'), 'production'],
            'no secret' => [fn () => config()->set('plorea.webhooks.secret', ''), 'PLOREA_WEBHOOK_SECRET'],
        ];
    }

    /**
     * @param  callable(): void  $arrange
     */
    #[DataProvider('refusals')]
    public function test_it_refuses_and_sends_nothing(callable $arrange, string $message): void
    {
        Event::fake();
        $arrange();

        PloreaServer::tool(SendTestWebhook::class, ['type' => 'payment.authorised'])
            ->assertHasErrors([$message]);

        Event::assertNothingDispatched();
    }

    public function test_a_live_client_is_refused_after_the_config_changes_to_test(): void
    {
        Event::fake();
        config()->set('plorea.environment', 'live');
        config()->set('plorea.api_key', 'plr_live_FAKE');
        $this->app->make(Client::class);
        config()->set('plorea.environment', 'test');
        config()->set('plorea.api_key', 'plr_test_FAKE');

        PloreaServer::tool(SendTestWebhook::class, ['type' => 'payment.authorised'])
            ->assertHasErrors(['points at live']);

        Event::assertNothingDispatched();
    }

    public function test_allow_live_does_not_unlock_it(): void
    {
        Event::fake();
        config()->set('plorea.environment', 'live');
        config()->set('plorea.mcp.allow_live', true);

        PloreaServer::tool(SendTestWebhook::class, ['type' => 'payment.authorised'])
            ->assertHasErrors(['points at live']);

        Event::assertNothingDispatched();
    }

    public function test_it_never_calls_plorea(): void
    {
        Event::fake();
        Http::fake();

        $this->callTool(SendTestWebhook::class, ['type' => 'subscription.charge_succeeded']);

        Http::assertNothingSent();
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function invalidArguments(): array
    {
        return [
            'unknown type' => [['type' => 'payment.refunded'], 'Pass type'],
            'reference on a subscription type' => [['type' => 'subscription.charge_succeeded', 'reference' => 'X'], 'payment.* types only'],
            'subscription id on a payment type' => [['type' => 'payment.authorised', 'subscription_id' => 'sub_1'], 'subscription.* types only'],
            'unsafe characters' => [['type' => 'payment.authorised', 'reference' => 'a b'], 'letters, digits'],
            'trailing newline' => [['type' => 'payment.authorised', 'reference' => "ORDER-42\n"], 'letters, digits'],
            'too long' => [['type' => 'payment.authorised', 'reference' => str_repeat('a', 101)], 'letters, digits'],
        ];
    }

    /**
     * @param  array<string, string>  $arguments
     */
    #[DataProvider('invalidArguments')]
    public function test_invalid_arguments_send_nothing(array $arguments, string $message): void
    {
        Event::fake();

        PloreaServer::tool(SendTestWebhook::class, $arguments)
            ->assertHasErrors([$message]);

        Event::assertNothingDispatched();
    }
}
