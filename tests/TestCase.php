<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Tests;

use Illuminate\Support\Facades\Http;
use MemberFlow\Plorea\PloreaServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    protected function getPackageProviders($app): array
    {
        return [PloreaServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('plorea.api_key', 'plr_test_key');
        $app['config']->set('plorea.environment', 'test');
        $app['config']->set('plorea.tenant_id', 'test-tenant');
    }
}
