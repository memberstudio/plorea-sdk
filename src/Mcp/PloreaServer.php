<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Mcp;

use Laravel\Mcp\Server;
use MemberFlow\Plorea\Mcp\Tools\CheckSetup;
use MemberFlow\Plorea\Mcp\Tools\FieldNote;

/**
 * A local, read-only MCP server for agents that build on Plorea.
 *
 * Registered as "plorea" when laravel/mcp is installed and
 * plorea.mcp.enabled is true. No tool on it moves money, and no tool
 * prints the API key or the webhook secret.
 */
class PloreaServer extends Server
{
    protected string $name = 'Plorea';

    protected string $version = '1.0.0';

    protected string $instructions = <<<'MARKDOWN'
        Read-only tools for the Plorea Payments SDK (memberflow/plorea).

        - Run `check-setup` before you debug a 401, a 403 or a missing webhook. Most Plorea setup mistakes fail silently.
        - Run `field-note` with an HTTP status, an exception class or a keyword before you assume how the Plorea API behaves. The notes are field-tested against the live API; the API often differs from what a reasonable design would do.

        These tools never create, charge, refund or cancel anything.
        MARKDOWN;

    /**
     * @var array<int, class-string<Server\Tool>>
     */
    protected array $tools = [
        CheckSetup::class,
        FieldNote::class,
    ];
}
