<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use SinaSaeedi\RuntimeMcp\Tests\Support\BootCapture;
use SinaSaeedi\RuntimeMcp\Tests\TestCase;

/*
 * Spec 5.11: boot validation reads config only. Under PHP-FPM it runs on every request, so it never
 * queries the database, also not for the throttle (Sina, 2026-10-19: a file store, not the app's).
 */

it('runs no query and opens no connection while it boots', function (array $config, string $environment) {
    $queries = 0;
    // The event, not DB::listen(): DB::listen() resolves the default connection itself.
    $listen = function (Application $app) use (&$queries): void {
        $app->make(Dispatcher::class)->listen(QueryExecuted::class, function () use (&$queries): void {
            $queries++;
        });
    };

    /** @var array<string, mixed> $config */
    $boot = BootCapture::boot([...$config, 'cache.default' => 'database'], $environment, $listen);

    expect($queries)->toBe(0);

    if ($boot->thrown === null) {
        expect(DB::getConnections())->toBe([]);
    }
})->with([
    'every rule passes' => [[], 'production'],
    'database.connection unset, strict' => [['runtime-mcp.database.connection' => null], 'production'],
    'database.connection unset, relaxed (warning)' => [['runtime-mcp.database.connection' => null], 'local'],
    'database.connection is the default' => [['runtime-mcp.database.connection' => 'testing'], 'production'],
    'no keys, strict' => [['runtime-mcp.auth.api_keys' => null], 'production'],
    'no keys, relaxed (throw)' => [['runtime-mcp.auth.api_keys' => null], 'local'],
    'a malformed key' => [['runtime-mcp.auth.api_keys' => TestCase::defaultKeyEntry().',x'], 'production'],
]);
