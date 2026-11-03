<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Tests\Feature\Mcp;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MemberFlow\Plorea\Contracts\Client;
use MemberFlow\Plorea\Enums\Environment;
use MemberFlow\Plorea\Mcp\Masker;
use MemberFlow\Plorea\Mcp\PloreaServer;
use MemberFlow\Plorea\Mcp\Tools\Lookup;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class LookupTest extends McpTestCase
{
    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function kinds(): array
    {
        return [
            'payment' => ['payment', 'GOLDEN-2026-001', 'payments/status/GOLDEN-2026-001', 'payment-status-refund-requested'],
            'payment link' => ['payment_link', 'pl_test_golden_link', 'pay/pl_test_golden_link', 'pay-page-with-merchant'],
            'subscription' => ['subscription', 'sub_test_golden', 'subscriptions/sub_test_golden', 'subscription-active'],
            'payment method' => ['payment_method', 'pm_test_golden_method', 'payment-methods/pm_test_golden_method', 'payment-method-active'],
        ];
    }

    #[DataProvider('kinds')]
    public function test_each_kind_reads_with_one_get(string $kind, string $id, string $path, string $fixture): void
    {
        Http::fake(["payments.plorea.no/{$path}*" => Http::response($this->fixture($fixture))]);

        $result = $this->callTool(Lookup::class, ['kind' => $kind, 'id' => $id]);

        $this->assertTrue($result['found']);
        $this->assertTrue($result['masked']);
        $this->assertIsArray($result['data']);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_starts_with($request->url(), "https://payments.plorea.no/{$path}"));
    }

    public function test_charges_come_newest_first(): void
    {
        $charges = $this->fixture('subscription-charges');
        $charges['items'] = array_reverse($charges['items']);
        Http::fake(['payments.plorea.no/subscriptions/sub_test_golden/charges*' => Http::response($charges)]);

        $result = $this->callTool(Lookup::class, ['kind' => 'subscription_charges', 'id' => 'sub_test_golden']);

        $this->assertSame(2, $result['count']);
        $this->assertSame(['chg_test_golden_2', 'chg_test_golden_1'], array_column($result['items'], 'id'));
    }

    public function test_subscriptions_for_an_external_id_are_masked(): void
    {
        Http::fake(['payments.plorea.no/subscriptions?*' => Http::response($this->fixture('subscription-list'))]);

        $result = $this->callTool(Lookup::class, ['kind' => 'subscriptions_for_external_id', 'id' => 'GOLDEN-EXT-001']);

        $this->assertSame(1, $result['count']);
        $this->assertSame('active', $result['items'][0]['status']);
        $this->assertSame(Masker::MASK, $result['items'][0]['description']);
    }

    public function test_masking_can_be_turned_off(): void
    {
        config()->set('plorea.mcp.mask', false);
        Http::fake(['payments.plorea.no/subscriptions/*' => Http::response($this->fixture('subscription-active'))]);

        $result = $this->callTool(Lookup::class, ['kind' => 'subscription', 'id' => 'sub_test_golden']);

        $this->assertFalse($result['masked']);
        $this->assertSame('golden-shopper-001', $result['data']['shopperReference']);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unclearMaskValues(): array
    {
        return [
            'missing' => [null],
            'empty' => [''],
            'whitespace' => ['   '],
            'unclear' => ['maybe'],
        ];
    }

    #[DataProvider('unclearMaskValues')]
    public function test_an_unclear_mask_setting_keeps_masking_on(mixed $value): void
    {
        config()->set('plorea.mcp.mask', $value);
        Http::fake(['payments.plorea.no/subscriptions/*' => Http::response($this->fixture('subscription-active'))]);

        $result = $this->callTool(Lookup::class, ['kind' => 'subscription', 'id' => 'sub_test_golden']);

        $this->assertTrue($result['masked']);
        $this->assertSame(Masker::MASK, $result['data']['shopperReference']);
    }

    /**
     * @return array<string, array{mixed, string, mixed}>
     */
    public static function liveConfigurations(): array
    {
        return [
            'live environment' => [Environment::Live, 'plr_live_FAKE', false],
            'live string' => ['live', 'plr_live_FAKE', null],
            'live key with the test environment' => ['test', 'plr_live_FAKE', 'maybe'],
        ];
    }

    #[DataProvider('liveConfigurations')]
    public function test_live_is_refused_unless_allowed(mixed $environment, string $key, mixed $allowLive): void
    {
        config()->set('plorea.environment', $environment);
        config()->set('plorea.api_key', $key);
        config()->set('plorea.mcp.allow_live', $allowLive);
        Http::fake();

        PloreaServer::tool(Lookup::class, ['kind' => 'payment', 'id' => 'GOLDEN-2026-001'])
            ->assertHasErrors(['PLOREA_MCP_ALLOW_LIVE=true']);

        Http::assertNothingSent();
    }

    public function test_a_live_client_is_refused_after_the_config_changes_to_test(): void
    {
        config()->set('plorea.environment', 'live');
        config()->set('plorea.api_key', 'plr_live_FAKE');
        $this->app->make(Client::class);
        config()->set('plorea.environment', 'test');
        config()->set('plorea.api_key', 'plr_test_FAKE');
        Http::fake();

        PloreaServer::tool(Lookup::class, ['kind' => 'payment', 'id' => 'GOLDEN-2026-001'])
            ->assertHasErrors(['PLOREA_MCP_ALLOW_LIVE=true']);

        Http::assertNothingSent();
    }

    public function test_whitespace_does_not_allow_live(): void
    {
        config()->set('plorea.environment', 'live');
        config()->set('plorea.mcp.allow_live', '  ');
        Http::fake();

        PloreaServer::tool(Lookup::class, ['kind' => 'payment', 'id' => 'GOLDEN-2026-001'])
            ->assertHasErrors(['PLOREA_MCP_ALLOW_LIVE=true']);

        Http::assertNothingSent();
    }

    public function test_live_reads_when_allowed(): void
    {
        config()->set('plorea.environment', 'live');
        config()->set('plorea.api_key', 'plr_live_FAKE');
        config()->set('plorea.mcp.allow_live', 'true');
        Http::fake(['payments.plorea.no/*' => Http::response($this->fixture('payment-status-refund-requested'))]);

        $result = $this->callTool(Lookup::class, ['kind' => 'payment', 'id' => 'GOLDEN-2026-001']);

        $this->assertTrue($result['found']);
        $this->assertTrue($result['masked']);
    }

    public function test_an_invalid_environment_is_refused_without_echoing_it(): void
    {
        config()->set('plorea.environment', 'plr_live_DO_NOT_PRINT');
        Http::fake();

        PloreaServer::tool(Lookup::class, ['kind' => 'payment', 'id' => 'GOLDEN-2026-001'])
            ->assertHasErrors(['PLOREA_ENVIRONMENT is not'])
            ->assertDontSee('DO_NOT_PRINT');

        Http::assertNothingSent();
    }

    public function test_a_404_is_an_answer(): void
    {
        Http::fake(['payments.plorea.no/*' => Http::response($this->fixture('payment-status-not-found'), 404)]);

        $result = $this->callTool(Lookup::class, ['kind' => 'payment', 'id' => 'UNKNOWN-1']);

        $this->assertFalse($result['found']);
        $this->assertStringContainsString('other environment', $result['detail']);
    }

    public function test_a_403_points_at_check_setup(): void
    {
        Http::fake(['payments.plorea.no/*' => Http::response(['error' => 'Forbidden'], 403)]);

        PloreaServer::tool(Lookup::class, ['kind' => 'payment', 'id' => 'GOLDEN-2026-001'])
            ->assertHasErrors(['Run check-setup']);
    }

    public function test_a_failed_read_never_prints_the_message(): void
    {
        config()->set('app.debug', true);
        Http::fake(['payments.plorea.no/*' => fn (): never => throw new RuntimeException('plr_test_FAKE_DO_NOT_PRINT')]);

        PloreaServer::tool(Lookup::class, ['kind' => 'payment', 'id' => 'GOLDEN-2026-001'])
            ->assertHasErrors(['The read failed'])
            ->assertDontSee('DO_NOT_PRINT');
    }

    public function test_an_unknown_kind_is_an_error(): void
    {
        Http::fake();

        PloreaServer::tool(Lookup::class, ['kind' => 'refund', 'id' => 'GOLDEN-2026-001'])
            ->assertHasErrors(['Pass kind']);

        Http::assertNothingSent();
    }

    public function test_the_server_lists_the_tool(): void
    {
        Http::fake(['payments.plorea.no/*' => Http::response($this->fixture('pay-page-with-merchant'))]);

        PloreaServer::tool(Lookup::class, ['kind' => 'payment_link', 'id' => 'pl_test_golden_link'])
            ->assertOk()
            ->assertSee('GOLDEN-2026-002')
            ->assertDontSee('Golden fixture product')
            ->assertDontSee('Golden Fixture Gym AS');
    }
}
