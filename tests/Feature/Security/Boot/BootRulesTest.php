<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use SinaSaeedi\RuntimeMcp\Plugins\Core\CorePlugin;
use SinaSaeedi\RuntimeMcp\Plugins\PluginRegistry;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootIssue;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootRule;
use SinaSaeedi\RuntimeMcp\Security\Boot\Rules\ApiKeysFormat;
use SinaSaeedi\RuntimeMcp\Security\Boot\Rules\ApiKeysPresent;
use SinaSaeedi\RuntimeMcp\Security\Boot\Rules\EnvironmentName;
use SinaSaeedi\RuntimeMcp\Security\Boot\Rules\PluginsInstalled;
use SinaSaeedi\RuntimeMcp\Security\Boot\Rules\ReadOnlyConnection;
use SinaSaeedi\RuntimeMcp\Security\Boot\Severity;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Plugins\FakeOptionalPlugin;
use SinaSaeedi\RuntimeMcp\Tests\TestCase;

/*
 * Spec 5.11: each core rule alone. The endpoint stays disabled, so the provider validates nothing;
 * each rule is built after the boot and reads the config when check() runs.
 */

/**
 * @param  class-string<BootRule>  $rule
 * @param  array<array-key, mixed>  $config
 * @return list<BootIssue>
 */
function bootRulesCheck(string $rule, array $config = []): array
{
    foreach ($config as $key => $value) {
        config([(string) $key => $value]);
    }

    $instance = app($rule);

    if (! $instance instanceof BootRule) {
        throw new LogicException("[{$rule}] is not a BootRule.");
    }

    return $instance->check();
}

/**
 * @param  list<BootIssue>  $issues
 * @return list<array{string, string, string}>
 */
function bootRulesFlat(array $issues): array
{
    return array_map(fn (BootIssue $issue): array => [$issue->ruleId, $issue->severity->value, $issue->message], $issues);
}

it('gives each rule a stable id', function () {
    expect(app(ApiKeysPresent::class)->id())->toBe('api_keys.present')
        ->and(app(ApiKeysFormat::class)->id())->toBe('api_keys.format')
        ->and(app(ReadOnlyConnection::class)->id())->toBe('database.connection')
        ->and(app(PluginsInstalled::class)->id())->toBe('plugins.installed')
        ->and(app(EnvironmentName::class)->id())->toBe('environment.name');
});

// api_keys.present

it('passes api_keys.present when the ring holds a key, or a malformed entry', function (string $keys) {
    expect(bootRulesCheck(ApiKeysPresent::class, ['runtime-mcp.auth.api_keys' => $keys]))->toBe([]);
})->with([
    'a key' => [TestCase::defaultKeyEntry()],
    'only a malformed entry (api_keys.format reports it)' => ['sina:abc'],
]);

it('skips api_keys.present for every driver except api_key', function (mixed $driver) {
    expect(bootRulesCheck(ApiKeysPresent::class, ['runtime-mcp.auth.api_keys' => null, 'runtime-mcp.auth.driver' => $driver]))->toBe([]);
})->with(['sanctum', 'passport', null, [['api_key']]]);

it('fails api_keys.present with an empty ring and no STDIO principal', function (mixed $keys, mixed $principal) {
    expect(bootRulesFlat(bootRulesCheck(ApiKeysPresent::class, [
        'runtime-mcp.auth.api_keys' => $keys,
        'runtime-mcp.auth.stdio_principal' => $principal,
    ])))->toBe([['api_keys.present', 'error', ApiKeysPresent::ERROR]]);
})->with([
    'null' => [null],
    'empty' => [''],
    'commas only' => [' , ,'],
])->with([
    'no principal' => [null],
    'an invalid principal' => ['local dev'],
]);

it('only warns on api_keys.present when a valid STDIO principal is set', function () {
    expect(bootRulesFlat(bootRulesCheck(ApiKeysPresent::class, [
        'runtime-mcp.auth.api_keys' => null,
        'runtime-mcp.auth.stdio_principal' => 'local-dev',
    ])))->toBe([[
        'api_keys.present', 'warning',
        'RUNTIME_MCP_API_KEYS is empty, so the HTTP endpoint stays off. The STDIO server runs as `local-dev`.',
    ]]);
});

it('fails api_keys.present for a key-shaped STDIO principal, and never names it (01-12)', function () {
    $key = 'rmcp_'.str_repeat('A', 43);

    $issues = bootRulesFlat(bootRulesCheck(ApiKeysPresent::class, [
        'runtime-mcp.auth.api_keys' => null,
        'runtime-mcp.auth.stdio_principal' => $key,
    ]));

    expect($issues)->toBe([['api_keys.present', 'error', ApiKeysPresent::ERROR]])
        ->and(str_contains(json_encode($issues, JSON_THROW_ON_ERROR), $key))->toBeFalse();
});

it('tells the developer how to get past the relaxed throw in the api_keys.present error', function () {
    expect(ApiKeysPresent::ERROR)->toBe(
        'RUNTIME_MCP_API_KEYS is empty. Create a key with `php artisan runtime-mcp:key <name>` '
        .'(in a relaxed environment, set RUNTIME_MCP_ENABLED=false first: artisan cannot start while this error stands), '
        .'or set RUNTIME_MCP_STDIO_PRINCIPAL to use only the STDIO server.'
    );
});

// api_keys.format

it('passes api_keys.format for a valid ring and an empty one', function (mixed $keys) {
    expect(bootRulesCheck(ApiKeysFormat::class, ['runtime-mcp.auth.api_keys' => $keys]))->toBe([]);
})->with([
    'a key' => [TestCase::defaultKeyEntry()],
    'two keys and a trailing comma' => [TestCase::defaultKeyEntry().',b:'.str_repeat('a', 64).','],
    'null' => [null],
    'empty' => [''],
]);

it('fails api_keys.format with the positions only', function (mixed $keys, string $message) {
    $issues = bootRulesCheck(ApiKeysFormat::class, ['runtime-mcp.auth.api_keys' => $keys]);

    expect(bootRulesFlat($issues))->toBe([['api_keys.format', 'error', $message]]);
})->with([
    'one bad entry' => [TestCase::defaultKeyEntry().',sina:abc', 'RUNTIME_MCP_API_KEYS entry 2 is not in the name:sha256hex format.'],
    'two bad entries' => ['x,'.TestCase::defaultKeyEntry().',y', 'RUNTIME_MCP_API_KEYS entries 1 and 3 are not in the name:sha256hex format.'],
    'three bad entries, an empty one counted' => ['x,,y,'.TestCase::defaultKeyEntry().',z', 'RUNTIME_MCP_API_KEYS entries 1, 3 and 5 are not in the name:sha256hex format.'],
    'not a string' => [['a:b'], 'RUNTIME_MCP_API_KEYS entry 1 is not in the name:sha256hex format.'],
]);

it('never puts the text of an entry in the api_keys.format message', function () {
    $raw = 'rmcp_'.str_repeat('Q', 43);
    $nearHash = 'sina:'.str_repeat('f', 63);
    $issues = bootRulesCheck(ApiKeysFormat::class, ['runtime-mcp.auth.api_keys' => $raw.','.$nearHash]);
    $message = $issues[0]->message ?? '';

    expect(str_contains($message, $raw))->toBeFalse()
        ->and(str_contains($message, $nearHash))->toBeFalse()
        ->and(str_contains($message, 'rmcp_'))->toBeFalse()
        ->and(str_contains($message, 'sina'))->toBeFalse();
});

it('checks api_keys.format for every driver', function () {
    expect(bootRulesCheck(ApiKeysFormat::class, ['runtime-mcp.auth.driver' => 'sanctum', 'runtime-mcp.auth.api_keys' => 'x']))->toHaveCount(1);
});

// database.connection

it('fails database.connection when it is unset: an error in a strict environment', function (mixed $connection) {
    currentTest()->bootWith(environment: 'production');

    expect(bootRulesFlat(bootRulesCheck(ReadOnlyConnection::class, ['runtime-mcp.database.connection' => $connection])))->toBe([[
        'database.connection', 'error',
        'RUNTIME_MCP_DB_CONNECTION is not set. The `models` and `database` plugins need a separate read-only connection.',
    ]]);
})->with([
    'null' => [null],
    'empty' => [''],
    'blank' => ['   '],
    'not a string' => [['testing_readonly']],
]);

it('only warns on database.connection in a relaxed environment', function (string $environment) {
    currentTest()->bootWith(environment: $environment);

    $issues = bootRulesCheck(ReadOnlyConnection::class, ['runtime-mcp.database.connection' => null]);

    expect($issues)->toHaveCount(1)
        ->and($issues[0]->severity ?? null)->toBe(Severity::Warning);
})->with(['local', 'testing']);

it('follows environments.relaxed for the database.connection severity', function () {
    currentTest()->bootWith(['runtime-mcp.environments.relaxed' => ['dev']], 'local');
    $local = bootRulesCheck(ReadOnlyConnection::class, ['runtime-mcp.database.connection' => null]);

    currentTest()->bootWith(['runtime-mcp.environments.relaxed' => ['dev']], 'dev');
    $dev = bootRulesCheck(ReadOnlyConnection::class, ['runtime-mcp.database.connection' => null]);

    expect($local[0]->severity ?? null)->toBe(Severity::Error)
        ->and($dev[0]->severity ?? null)->toBe(Severity::Warning);
});

it('fails database.connection when it names the default connection', function () {
    currentTest()->bootWith(environment: 'staging');

    expect(bootRulesFlat(bootRulesCheck(ReadOnlyConnection::class, ['runtime-mcp.database.connection' => 'testing'])))->toBe([[
        'database.connection', 'error',
        'RUNTIME_MCP_DB_CONNECTION names `testing`, the default connection. The `models` and `database` plugins need a separate read-only connection.',
    ]]);
});

it('passes database.connection for a separate connection name, without opening it', function () {
    currentTest()->bootWith(environment: 'production');

    expect(bootRulesCheck(ReadOnlyConnection::class, ['runtime-mcp.database.connection' => 'testing_readonly']))->toBe([])
        // A name that config/database.php does not define is doctor's (01-13), not a boot rule (Sina, 2026-10-19).
        ->and(bootRulesCheck(ReadOnlyConnection::class, ['runtime-mcp.database.connection' => 'nope']))->toBe([])
        ->and(DB::getConnections())->toBe([]);
});

it('names only the active plugins that read the database', function (array $plugins, ?string $message) {
    currentTest()->bootWith(environment: 'production');
    $settings = [];

    foreach ($plugins as $name => $on) {
        $settings[(string) $name] = $on;
    }

    $issues = bootRulesCheck(ReadOnlyConnection::class, [
        'runtime-mcp.database.connection' => null,
        'runtime-mcp.plugins' => $settings,
    ]);

    expect($issues[0]->message ?? null)->toBe($message);
})->with([
    'both off' => [['models' => false, 'database' => false], null],
    'only database' => [['models' => false], 'RUNTIME_MCP_DB_CONNECTION is not set. The `database` plugin needs a separate read-only connection.'],
    'only models' => [['database' => false], 'RUNTIME_MCP_DB_CONNECTION is not set. The `models` plugin needs a separate read-only connection.'],
    'every plugin off' => [['core' => false, 'models' => false, 'database' => false, 'queue' => false, 'logs' => false], null],
]);

// plugins.installed

it('fails plugins.installed for an enabled plugin whose package is missing', function () {
    app()->scoped(PluginRegistry::class, fn (Application $app): PluginRegistry => new PluginRegistry(
        $app, $app->make(Repository::class), [CorePlugin::class, FakeOptionalPlugin::class],
    ));

    expect(bootRulesCheck(PluginsInstalled::class, ['runtime-mcp.plugins.fake_optional' => false]))->toBe([])
        ->and(bootRulesFlat(bootRulesCheck(PluginsInstalled::class, ['runtime-mcp.plugins.fake_optional' => true])))->toBe([[
            'plugins.installed', 'error', 'Plugin `fake_optional` is enabled, but `acme/not-installed` is not installed.',
        ]]);
});

it('passes plugins.installed for the base plugins', function () {
    expect(bootRulesCheck(PluginsInstalled::class))->toBe([]);
});

// environment.name (01-02 review)

it('fails environment.name for an empty or blank environment name', function (string $environment) {
    currentTest()->bootWith(environment: $environment);

    expect(bootRulesFlat(bootRulesCheck(EnvironmentName::class)))->toBe([[
        'environment.name', 'error',
        'The environment name is empty. Set APP_ENV, for example to `production`, so the server name tells the agent which server it is on.',
    ]]);
})->with([
    'empty' => [''],
    'only spaces' => ['  '],
]);

it('passes environment.name for a named environment', function (string $environment) {
    currentTest()->bootWith(environment: $environment);

    expect(bootRulesCheck(EnvironmentName::class))->toBe([]);
})->with(['staging', 'prod-eu']);
