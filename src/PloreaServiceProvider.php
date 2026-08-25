<?php

declare(strict_types=1);

namespace MemberFlow\Plorea;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;
use MemberFlow\Plorea\Contracts\Client;
use MemberFlow\Plorea\Http\PloreaClient;

class PloreaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/plorea.php', 'plorea');

        $this->app->singleton(Client::class, function (Application $app): Client {
            /** @var array<string, mixed> $config */
            $config = $app->make(Repository::class)->get('plorea', []);

            return new PloreaClient($app->make(Factory::class), $config);
        });

        $this->app->singleton(PloreaManager::class, fn ($app): PloreaManager => new PloreaManager($app));
    }

    public function boot(): void
    {
        $this->registerPublishing();
        $this->registerRoutes();
        $this->registerAboutCommand();
    }

    protected function registerPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/plorea.php' => $this->app->configPath('plorea.php'),
        ], 'plorea-config');
    }

    protected function registerRoutes(): void
    {
        if (! (bool) $this->config()->get('plorea.webhooks.enabled', true)) {
            return;
        }

        if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
            return;
        }

        $this->loadRoutesFrom(__DIR__.'/../routes/webhooks.php');
    }

    protected function registerAboutCommand(): void
    {
        if (! class_exists(AboutCommand::class)) {
            return;
        }

        AboutCommand::add('Plorea', fn (): array => [
            'Environment' => (string) $this->config()->get('plorea.environment'),
            'Tenant' => (string) ($this->config()->get('plorea.tenant_id') ?? '—'),
            'Webhooks' => (bool) $this->config()->get('plorea.webhooks.enabled') ? 'enabled' : 'disabled',
        ]);
    }

    protected function config(): Repository
    {
        return $this->app->make(Repository::class);
    }
}
