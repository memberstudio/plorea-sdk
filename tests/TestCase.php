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

    /**
     * An anonymized golden fixture from tests/Fixtures.
     *
     * @return array<string, mixed>
     */
    protected function fixture(string $name): array
    {
        $contents = file_get_contents(__DIR__.'/Fixtures/'.$name.'.json');

        $this->assertIsString($contents);

        /** @var array<string, mixed> $data */
        $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        return $data;
    }
}
