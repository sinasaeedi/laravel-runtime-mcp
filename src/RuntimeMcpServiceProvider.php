<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp;

use Illuminate\Support\ServiceProvider;

class RuntimeMcpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/runtime-mcp.php', 'runtime-mcp');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/runtime-mcp.php' => $this->app->configPath('runtime-mcp.php'),
            ], 'runtime-mcp-config');
        }

        // Each later concern (the MCP server, the audit channel, boot validation, the commands)
        // is registered by the story that builds it. Do not add a "misc" section here.
    }
}
