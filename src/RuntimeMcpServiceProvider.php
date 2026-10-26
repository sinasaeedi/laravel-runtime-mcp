<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\ServiceProvider;
use SinaSaeedi\RuntimeMcp\Audit\AuditChannel;
use SinaSaeedi\RuntimeMcp\Audit\AuditLogger;
use SinaSaeedi\RuntimeMcp\Console\DoctorCommand;
use SinaSaeedi\RuntimeMcp\Mcp\McpRoutes;
use SinaSaeedi\RuntimeMcp\Plugins\PluginRegistry;
use SinaSaeedi\RuntimeMcp\Security\Auth\CurrentPrincipal;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootGuard;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootState;
use SinaSaeedi\RuntimeMcp\Security\Environment;
use SinaSaeedi\RuntimeMcp\Security\Grants\ColumnMask;
use SinaSaeedi\RuntimeMcp\Security\Grants\DeniedTables;
use SinaSaeedi\RuntimeMcp\Security\Grants\RoleSqlGenerator;
use SinaSaeedi\RuntimeMcp\Security\ReadOnly\RefuseReadOnlyCommit;
use SinaSaeedi\RuntimeMcp\Security\ReadOnly\ReopenReadOnlyTransaction;
use SinaSaeedi\RuntimeMcp\Security\Redaction\Redactor;
use SinaSaeedi\RuntimeMcp\Security\Sql\SessionLedger;

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

        // Spec 5.2, 5.13, rule 14 (01-14): scoped, so a read-only mark or a pending session reset never
        // outlives its request. ReadOnlySession is not bound, so each make() gets this request's ledger.
        $this->app->scoped(SessionLedger::class);

        // Spec 5.11 (01-10): a singleton, not scoped. It holds the boot report of this app instance;
        // under Octane the provider boots once per worker (no request data, rule 14).
        $this->app->singleton(BootState::class);

        // Spec 7.1 (01-11): bind, not singleton or scoped. It reads the config on each make(), and
        // make() throws InvalidGrantConfig for a malformed list. Boot never resolves it (spec 5.11).
        $this->app->bind(RoleSqlGenerator::class, function (Application $app): RoleSqlGenerator {
            $config = $app->make(Repository::class);
            $appName = $config->get('app.name');

            return new RoleSqlGenerator(
                ColumnMask::fromConfig($config),
                DeniedTables::fromConfig($config),
                is_string($appName) ? $appName : '',
                $app->make(Environment::class)->name(),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/runtime-mcp.php' => $this->app->configPath('runtime-mcp.php'),
            ], 'runtime-mcp-config');

            // Spec 7.3 (01-12).
            $this->commands([DoctorCommand::class]);
        }

        // Spec 5.7: the audit channel, unless the app defines it. Before the enabled check: the
        // audit also serves STDIO and never turns off (rule 12).
        AuditChannel::register(
            $this->app->make('config'),
            $this->app->storagePath('logs/runtime-mcp.log'),
        );

        // Spec 5.9 (01-16, Sina Q1): app code inside a read tool cannot commit the read-only transaction,
        // and an unbalanced rollback begins a new one (Sina, 2026-10-26). Before the enabled check, like the
        // audit: the test kit and STDIO run tools too. Both act only on a connection that the request's
        // SessionLedger marks, so the app's own commits and rollbacks are untouched.
        $events = $this->app->make(Dispatcher::class);
        $events->listen(TransactionCommitting::class, RefuseReadOnlyCommit::class);
        $events->listen(TransactionRolledBack::class, ReopenReadOnlyTransaction::class);

        // Spec 5.11: boot validation first (01-10). Strict: a failed rule registers nothing. Relaxed:
        // check() throws. Then 01-02 the HTTP endpoint and 01-06 the STDIO server, each only if allowed.
        if ($this->app->make('config')->get('runtime-mcp.enabled') === true) {
            $report = $this->app->make(BootGuard::class)->check();

            $this->app->make(McpRoutes::class)->register(http: $report->httpAllowed(), stdio: $report->stdioAllowed());
        }

        // Each later concern (the commands) is registered by the story that builds it. Do not add a
        // "misc" section here.
    }
}
