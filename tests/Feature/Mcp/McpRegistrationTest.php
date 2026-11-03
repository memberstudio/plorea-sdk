<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Tests\Feature\Mcp;

use Illuminate\Foundation\Application;
use Laravel\Mcp\Facades\Mcp;
use Orchestra\Testbench\Attributes\DefineEnvironment;

class McpRegistrationTest extends McpTestCase
{
    public function test_the_server_is_off_by_default(): void
    {
        $this->assertNull(Mcp::getLocalServer('plorea'));
    }

    #[DefineEnvironment('enableMcp')]
    public function test_the_server_registers_when_enabled(): void
    {
        $this->assertNotNull(Mcp::getLocalServer('plorea'));
    }

    protected function enableMcp(Application $app): void
    {
        $app['config']->set('plorea.mcp.enabled', true);
    }
}
