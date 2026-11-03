<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Tests\Feature\Mcp;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Mcp\Server\Tool;
use MemberFlow\Plorea\Tests\TestCase;

abstract class McpTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), McpServiceProvider::class];
    }

    /**
     * Runs a tool and decodes its JSON answer.
     *
     * @param  class-string<Tool>  $tool
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function callTool(string $tool, array $arguments = []): array
    {
        /** @var Tool&object{handle: callable} $instance */
        $instance = $this->app->make($tool);

        /** @var Response $response */
        $response = $instance->handle(new Request($arguments));

        /** @var array<string, mixed> $data */
        $data = json_decode((string) $response->content(), true, flags: JSON_THROW_ON_ERROR);

        return $data;
    }
}
