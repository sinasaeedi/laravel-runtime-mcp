<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use SinaSaeedi\RuntimeMcp\Security\Grants\InvalidGrantConfig;
use SinaSaeedi\RuntimeMcp\Security\Grants\RoleSqlGenerator;
use SinaSaeedi\RuntimeMcp\Security\Grants\SchemaSnapshot;
use SinaSaeedi\RuntimeMcp\Security\UnsupportedDriver;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Grants\FixtureSnapshot;

function roleSqlBindingHeader(): string
{
    return explode("\n", app(RoleSqlGenerator::class)->forPostgres(FixtureSnapshot::postgres()))[0];
}

it('puts the app name and the environment in the header', function () {
    currentTest()->bootWith(['app.name' => 'Shop'], 'staging');

    expect(roleSqlBindingHeader())->toBe('-- Runtime MCP read-only role for Shop (staging).');
});

it('names the app "this app" when app.name is not a string', function () {
    currentTest()->bootWith(['app.name' => ['not', 'a', 'name']], 'production');

    expect(roleSqlBindingHeader())->toBe('-- Runtime MCP read-only role for this app (production).');
});

it('generates the golden SQL from the default config', function () {
    currentTest()->bootWith(environment: 'production');

    $sql = file_get_contents(dirname(__DIR__, 3).'/Fixtures/Grants/postgres.sql');

    expect(app(RoleSqlGenerator::class)->forPostgres(FixtureSnapshot::postgres()))->toBe($sql);
});

it('reads the config again on each make()', function () {
    $first = app(RoleSqlGenerator::class);

    config()->set('runtime-mcp.database.masked_columns', ['*']);
    $second = app(RoleSqlGenerator::class);

    expect($second)->not->toBe($first)
        ->and($first->forPostgres(FixtureSnapshot::postgres()))->toContain('GRANT SELECT ON TABLE "public"."orders"')
        ->and($second->forPostgres(FixtureSnapshot::postgres()))->not->toContain('GRANT SELECT');
});

it('throws InvalidGrantConfig on make() for a malformed list, and the app still boots', function (string $key, mixed $value) {
    currentTest()->bootWith([
        'runtime-mcp.enabled' => true,
        "runtime-mcp.database.{$key}" => $value,
    ], 'production');

    // Boot validation reads config only (spec 5.11): it never resolves the generator.
    expect(app()->isBooted())->toBeTrue()
        ->and(app()->resolved(RoleSqlGenerator::class))->toBeFalse();

    $thrown = null;

    try {
        app(RoleSqlGenerator::class);
    } catch (InvalidGrantConfig $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(InvalidGrantConfig::class)
        ->and($thrown?->key)->toBe($key);
})->with([
    'masked_columns is a string' => ['masked_columns', 'password'],
    'masked_columns is null' => ['masked_columns', null],
    'denied_tables is null' => ['denied_tables', null],
    'denied_tables is a string' => ['denied_tables', 'sessions'],
]);

it('refuses to read a snapshot from the SQLite default connection', function () {
    $thrown = null;

    try {
        SchemaSnapshot::read(DB::connection());
    } catch (UnsupportedDriver $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(UnsupportedDriver::class)
        ->and($thrown?->driver)->toBe('sqlite')
        ->and($thrown?->getMessage())->toBe('Only PostgreSQL, MySQL and MariaDB are supported; this connection uses sqlite.');
});
