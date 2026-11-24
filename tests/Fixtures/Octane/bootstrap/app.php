<?php

declare(strict_types=1);

/*
 * 01-30 (spec 5.13): the app that a real Octane worker builds. Octane's ApplicationFactory requires this file, then
 * runs the HTTP kernel's bootstrappers on it, then clones it for every request. The base path is Testbench's skeleton,
 * so the storage (and the audit log) is the one the tests read. The test hands the config and the Gates in as
 * Octane's "initial instances" (OctaneWorker::CONFIG, OctaneWorker::GATES).
 */

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\BootProviders;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Octane\OctaneServiceProvider;
use SinaSaeedi\RuntimeMcp\RuntimeMcpServiceProvider;
use SinaSaeedi\RuntimeMcp\Tests\Support\OctaneWorker;

$app = Application::configure(basePath: OctaneWorker::skeleton())
    ->withProviders([McpServiceProvider::class, RuntimeMcpServiceProvider::class, OctaneServiceProvider::class], withBootstrapProviders: false)
    ->withMiddleware()
    ->withExceptions()
    ->create();

// After the providers register, as Testbench does: mergeConfigFrom() merges one level only, so a key set before it
// (runtime-mcp.auth.api_keys) would hide the package's other auth keys.
$app->afterBootstrapping(RegisterProviders::class, function (Application $app): void {
    $config = $app->make(Repository::class);
    $values = $app->make(OctaneWorker::CONFIG);

    foreach (is_array($values) ? $values : [] as $key => $value) {
        $config->set((string) $key, $value);
    }
});

$app->afterBootstrapping(BootProviders::class, function (Application $app): void {
    $gates = $app->make(OctaneWorker::GATES);

    foreach (is_array($gates) ? $gates : [] as $tool) {
        if (is_string($tool)) {
            $app->make(Gate::class)->define('runtime-mcp.'.$tool, fn (): bool => true);
        }
    }
});

return $app;
