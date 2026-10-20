<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Doctor\Checks;

use SinaSaeedi\RuntimeMcp\Doctor\Check;
use SinaSaeedi\RuntimeMcp\Doctor\CheckResult;
use SinaSaeedi\RuntimeMcp\Mcp\McpRoutes;

/**
 * A bad path or middleware registers no route (01-02, fail closed). It is not a boot rule (Sina,
 * 2026-10-19, 01-10 Q2), so doctor reports it. McpRoutes lives in src/Mcp/; calling it is allowed,
 * only a direct Laravel\Mcp import outside src/Mcp/ is not (spec 4.1).
 */
final class Route implements Check
{
    public function __construct(private readonly McpRoutes $routes) {}

    public function title(): string
    {
        return 'Route';
    }

    public function run(): array
    {
        $path = $this->routes->path();
        $middleware = $this->routes->middleware();

        if ($path !== null && $middleware !== null) {
            return [CheckResult::pass("The MCP route is `{$path}`.")];
        }

        $results = [];

        if ($path === null) {
            $results[] = CheckResult::fail(
                'runtime-mcp.path is not a non-empty string, so no MCP route is registered.',
                "Set runtime-mcp.path to a path such as '/mcp'.",
            );
        }

        if ($middleware === null) {
            $results[] = CheckResult::fail(
                'runtime-mcp.middleware is not a list of non-empty strings, so no MCP route is registered.',
                'Set runtime-mcp.middleware to a list of middleware class names or aliases, or to [].',
            );
        }

        return $results;
    }
}
