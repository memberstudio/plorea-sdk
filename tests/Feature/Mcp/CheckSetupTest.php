<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Tests\Feature\Mcp;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MemberFlow\Plorea\Enums\Environment;
use MemberFlow\Plorea\Mcp\PloreaServer;
use MemberFlow\Plorea\Mcp\Tools\CheckSetup;
use PHPUnit\Framework\Attributes\DataProvider;

class CheckSetupTest extends McpTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('plorea.webhooks.secret', 'whsec_FAKE_secret');
    }

    public function test_a_complete_test_setup_passes(): void
    {
        $result = $this->callTool(CheckSetup::class);

        $this->assertSame(0, $result['summary']['fail']);
        $this->assertSame(0, $result['summary']['warn']);
        $this->assertSame('pass', $this->statusOf($result, 'environment'));
        $this->assertSame('pass', $this->statusOf($result, 'webhook_route'));
        $this->assertSame('pass', $this->statusOf($result, 'webhook_csrf'));
        $this->assertSame('n/a', $this->statusOf($result, 'adyen_client_key'));
    }

    public function test_a_live_key_with_the_test_environment_fails(): void
    {
        config()->set('plorea.api_key', 'plr_live_FAKE');

        $result = $this->callTool(CheckSetup::class);

        $this->assertSame('fail', $this->statusOf($result, 'environment'));
        $this->assertStringContainsString('PLOREA_ENVIRONMENT=live', $this->check($result, 'environment')['fix']);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    #[DataProvider('brokenSetups')]
    public function test_it_reports_each_broken_setting(array $config, string $check, string $status): void
    {
        foreach ($config as $key => $value) {
            config()->set($key, $value);
        }

        $this->assertSame($status, $this->statusOf($this->callTool(CheckSetup::class), $check));
    }

    /**
     * @return array<string, array{array<string, mixed>, string, string}>
     */
    public static function brokenSetups(): array
    {
        return [
            'no api key' => [['plorea.api_key' => null], 'api_key', 'fail'],
            'not a plorea key' => [['plorea.api_key' => 'sk_FAKE'], 'api_key', 'warn'],
            'unknown environment' => [['plorea.environment' => 'staging'], 'environment', 'fail'],
            'padded environment' => [['plorea.environment' => ' test '], 'environment', 'fail'],
            'capitalised environment' => [['plorea.environment' => 'Test'], 'environment', 'fail'],
            'no tenant' => [['plorea.tenant_id' => ''], 'tenant_id', 'fail'],
            'no platform' => [['plorea.platform' => null], 'platform', 'warn'],
            'no webhook secret' => [['plorea.webhooks.secret' => null], 'webhook_secret', 'fail'],
            'no secret, verification off' => [['plorea.webhooks.secret' => null, 'plorea.webhooks.verify' => false], 'webhook_secret', 'warn'],
            'verification off in test' => [['plorea.webhooks.verify' => false], 'webhook_verify', 'warn'],
            'verification off in live' => [['plorea.webhooks.verify' => false, 'plorea.environment' => 'live', 'plorea.api_key' => 'plr_live_FAKE'], 'webhook_verify', 'fail'],
            'retries on' => [['plorea.http.retry.times' => 2], 'retries', 'warn'],
            'live adyen key in test' => [['plorea.adyen_client_key' => 'live_FAKE'], 'adyen_client_key', 'fail'],
            'unknown adyen key' => [['plorea.adyen_client_key' => 'FAKE'], 'adyen_client_key', 'warn'],
        ];
    }

    public function test_the_web_middleware_group_on_the_webhook_route_warns_about_csrf(): void
    {
        $this->app['router']->getRoutes()->getByName('plorea.webhook')?->middleware('web');

        $this->assertSame('warn', $this->statusOf($this->callTool(CheckSetup::class), 'webhook_csrf'));
    }

    public function test_csrf_middleware_attached_by_class_warns(): void
    {
        if (! class_exists(PreventRequestForgery::class)) {
            $this->markTestSkipped('PreventRequestForgery exists from Laravel 13.');
        }

        $this->app['router']->getRoutes()->getByName('plorea.webhook')?->middleware(PreventRequestForgery::class);

        $this->assertSame('warn', $this->statusOf($this->callTool(CheckSetup::class), 'webhook_csrf'));
    }

    public function test_an_app_subclass_of_the_csrf_middleware_warns(): void
    {
        $this->app['router']->getRoutes()->getByName('plorea.webhook')?->middleware(AppForgeryGuard::class);

        $this->assertSame('warn', $this->statusOf($this->callTool(CheckSetup::class), 'webhook_csrf'));
    }

    public function test_excluded_csrf_middleware_passes(): void
    {
        $this->app['router']->getRoutes()->getByName('plorea.webhook')
            ?->middleware(AppForgeryGuard::class)
            ->withoutMiddleware(AppForgeryGuard::class);

        $this->assertSame('pass', $this->statusOf($this->callTool(CheckSetup::class), 'webhook_csrf'));
    }

    public function test_a_failed_ping_never_prints_the_api_key(): void
    {
        config()->set('app.debug', true);
        config()->set('plorea.api_key', "plr_test_FAKE\nDO_NOT_PRINT");

        $response = PloreaServer::tool(CheckSetup::class, ['ping' => true]);

        $response->assertOk()->assertDontSee('DO_NOT_PRINT');

        $this->assertStringContainsString('could not be sent', (string) $this->check($this->callTool(CheckSetup::class, ['ping' => true]), 'ping')['detail']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function csrfSpellings(): array
    {
        return [
            'leading backslash' => ['\\'.VerifyCsrfToken::class],
            'other case' => [strtolower(VerifyCsrfToken::class)],
            'class alias' => ['MemberFlow\\Plorea\\Tests\\Feature\\Mcp\\AliasedCsrf'],
        ];
    }

    #[DataProvider('csrfSpellings')]
    public function test_every_spelling_of_the_csrf_middleware_warns(string $middleware): void
    {
        if (! class_exists('MemberFlow\\Plorea\\Tests\\Feature\\Mcp\\AliasedCsrf', false)) {
            class_alias(VerifyCsrfToken::class, 'MemberFlow\\Plorea\\Tests\\Feature\\Mcp\\AliasedCsrf');
        }

        $this->app['router']->getRoutes()->getByName('plorea.webhook')?->middleware($middleware);

        $this->assertSame('warn', $this->statusOf($this->callTool(CheckSetup::class), 'webhook_csrf'));
    }

    public function test_a_middleware_that_fails_to_load_never_prints_its_name(): void
    {
        config()->set('app.debug', true);
        $this->app['router']->aliasMiddleware('broken', 'plr_test_FAKE_DO_NOT_PRINT');
        $this->app['router']->getRoutes()->getByName('plorea.webhook')?->middleware('broken');
        spl_autoload_register($loader = function (string $class): void {
            if ($class === 'plr_test_FAKE_DO_NOT_PRINT') {
                throw new \RuntimeException("Class \"{$class}\" not found");
            }
        });

        try {
            PloreaServer::tool(CheckSetup::class)->assertOk()->assertDontSee('DO_NOT_PRINT');
            $this->assertSame('warn', $this->statusOf($this->callTool(CheckSetup::class), 'webhook_csrf'));
        } finally {
            spl_autoload_unregister($loader);
        }
    }

    public function test_the_retry_count_is_not_printed(): void
    {
        config()->set('plorea.http.retry.times', '123456789_DO_NOT_PRINT');

        $output = json_encode($this->callTool(CheckSetup::class), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('123456789', $output);
    }

    public function test_the_environment_enum_is_accepted(): void
    {
        config()->set('plorea.environment', Environment::Test);

        $this->assertSame('pass', $this->statusOf($this->callTool(CheckSetup::class), 'environment'));
    }

    public function test_disabled_webhooks_skip_the_webhook_checks(): void
    {
        config()->set('plorea.webhooks.enabled', false);

        $result = $this->callTool(CheckSetup::class);

        $this->assertSame('n/a', $this->statusOf($result, 'webhooks'));
        $this->assertNull($this->check($result, 'webhook_secret'));
    }

    public function test_it_never_prints_a_configured_secret(): void
    {
        config()->set('plorea.api_key', 'plr_live_FAKE_DO_NOT_PRINT');
        config()->set('plorea.webhooks.secret', 'whsec_FAKE_DO_NOT_PRINT');
        config()->set('plorea.adyen_client_key', 'test_FAKE_DO_NOT_PRINT');
        config()->set('plorea.tenant_id', 'tenant_FAKE_DO_NOT_PRINT');

        $output = json_encode($this->callTool(CheckSetup::class), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('DO_NOT_PRINT', $output);
    }

    public function test_it_never_echoes_a_secret_pasted_into_the_wrong_variable(): void
    {
        config()->set('plorea.environment', 'plr_live_FAKE_DO_NOT_PRINT');
        config()->set('plorea.adyen_client_key', 'live_FAKE_DO_NOT_PRINT');

        $output = json_encode($this->callTool(CheckSetup::class), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('DO_NOT_PRINT', $output);
    }

    public function test_it_never_prints_the_webhook_path(): void
    {
        $this->app['router']->post('hooks/FAKE_DO_NOT_PRINT', fn (): string => '')->name('plorea.webhook');
        $this->app['router']->getRoutes()->refreshNameLookups();

        $output = json_encode($this->callTool(CheckSetup::class), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('DO_NOT_PRINT', $output);
    }

    public function test_the_ping_passes_on_a_404(): void
    {
        Http::fake(['payments.plorea.no/payments/status/*' => Http::response(['error' => 'Payment not found'], 404)]);

        $result = $this->callTool(CheckSetup::class, ['ping' => true]);

        $this->assertSame('pass', $this->statusOf($result, 'ping'));
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://payments.plorea.no/payments/status/'.CheckSetup::PING_REFERENCE));
        Http::assertSentCount(1);
    }

    public function test_a_404_that_is_not_plorea_does_not_pass_the_ping(): void
    {
        Http::fake(['payments.plorea.no/*' => Http::response('<html>Not Found</html>', 404)]);

        $this->assertSame('warn', $this->statusOf($this->callTool(CheckSetup::class, ['ping' => true]), 'ping'));
    }

    public function test_the_ping_fails_on_a_403(): void
    {
        Http::fake(['payments.plorea.no/*' => Http::response(['error' => 'Forbidden'], 403)]);

        $this->assertSame('fail', $this->statusOf($this->callTool(CheckSetup::class, ['ping' => true]), 'ping'));
    }

    public function test_there_is_no_ping_unless_asked(): void
    {
        Http::fake();

        $this->assertNull($this->check($this->callTool(CheckSetup::class), 'ping'));
        Http::assertNothingSent();
    }

    public function test_the_server_runs_the_tool(): void
    {
        PloreaServer::tool(CheckSetup::class)
            ->assertOk()
            ->assertSee('"check":"environment"');
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{check: string, status: string, detail: string, fix: string|null}|null
     */
    protected function check(array $result, string $name): ?array
    {
        /** @var list<array{check: string, status: string, detail: string, fix: string|null}> $checks */
        $checks = $result['checks'];

        foreach ($checks as $check) {
            if ($check['check'] === $name) {
                return $check;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function statusOf(array $result, string $name): ?string
    {
        return $this->check($result, $name)['status'] ?? null;
    }
}

class AppForgeryGuard extends VerifyCsrfToken {}
