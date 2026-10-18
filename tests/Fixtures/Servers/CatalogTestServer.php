<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Servers;

use Illuminate\Container\Container;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Tools\ToolSearch;
use SinaSaeedi\RuntimeMcp\Mcp\McpToolAdapter;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\EchoTool;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\SecondEchoTool;

/**
 * Two adapters behind laravel/mcp's search_tools and execute_tools (spec 3.2), for the rule 3 test
 * only. The package itself never registers ToolSearch in v1.0.0 before 01-40.
 */
final class CatalogTestServer extends Server
{
    protected function boot(): void
    {
        $container = Container::getInstance();

        $this->tools = [ToolSearch::class => [
            new McpToolAdapter(new EchoTool, $container),
            new McpToolAdapter(new SecondEchoTool, $container),
        ]];
    }
}
