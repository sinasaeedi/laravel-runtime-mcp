<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp;

use Illuminate\Support\ServiceProvider;
use SinaSaeedi\RuntimeMcp\Audit\AuditChannel;
use SinaSaeedi\RuntimeMcp\Audit\AuditLogger;
use SinaSaeedi\RuntimeMcp\Mcp\McpRoutes;
use SinaSaeedi\RuntimeMcp\Plugins\PluginRegistry;
use SinaSaeedi\RuntimeMcp\Security\Auth\CurrentPrincipal;
use SinaSaeedi\RuntimeMcp\Security\Environment;
use SinaSaeedi\RuntimeMcp\Security\Redaction\Redactor;

class RuntimeMcpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/runtime-mcp.php', 'runtime-mcp');

        // Spec 4.6, rule 14: scoped, so Octane gets a fresh instance per request.
        $this->app->scoped(Environment::class);

        // Spec 4.2, rule 14: scoped, and it reads the config on every call.
        $this->app->scoped(PluginRegistry::class);

        // Spec 5.6, rule 14: scoped, so extra_patterns is read once per request, never kept across them.
        $this->app->scoped(Redactor::class);

        // Spec 5.7, rule 14: scoped; it resolves the Redactor and the log on every call.
        $this->app->scoped(AuditLogger::class);

        // Spec 4.4 step 1, 5.13, rule 14: scoped, so a principal never outlives its request.
        $this->app->scoped(CurrentPrincipal::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/runtime-mcp.php' => $this->app->configPath('runtime-mcp.php'),
            ], 'runtime-mcp-config');
        }

        // Spec 5.7: the audit channel, unless the app defines it. Before the enabled check: the
        // audit also serves STDIO and never turns off (rule 12).
        AuditChannel::register(
            $this->app->make('config'),
            $this->app->storagePath('logs/runtime-mcp.log'),
        );

        // 01-02: the HTTP endpoint; 01-06: the STDIO server. 01-10 adds boot validation in front of this call.
        if ($this->app->make('config')->get('runtime-mcp.enabled') === true) {
            $this->app->make(McpRoutes::class)->register();
        }

        // Each later concern (boot validation, the commands)
        // is registered by the story that builds it. Do not add a "misc" section here.
    }
}
