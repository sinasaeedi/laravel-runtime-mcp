<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Doctor\Check;
use SinaSaeedi\RuntimeMcp\Doctor\CheckResult;
use SinaSaeedi\RuntimeMcp\Doctor\Checks\AllowedHosts;
use SinaSaeedi\RuntimeMcp\Doctor\Checks\AllowedOrigins;
use SinaSaeedi\RuntimeMcp\Doctor\Checks\AuditLog;
use SinaSaeedi\RuntimeMcp\Doctor\Checks\AuthDriver;
use SinaSaeedi\RuntimeMcp\Doctor\Checks\BootRules;
use SinaSaeedi\RuntimeMcp\Doctor\Checks\Endpoint;
use SinaSaeedi\RuntimeMcp\Doctor\Checks\LinkedUsers;
use SinaSaeedi\RuntimeMcp\Doctor\Checks\MaskLists;
use SinaSaeedi\RuntimeMcp\Doctor\Checks\Plugins;
use SinaSaeedi\RuntimeMcp\Doctor\Checks\RedactionPatterns;
use SinaSaeedi\RuntimeMcp\Doctor\Checks\Route;
use SinaSaeedi\RuntimeMcp\Doctor\Checks\StdioPrincipal;
use SinaSaeedi\RuntimeMcp\Doctor\CheckStatus;
use SinaSaeedi\RuntimeMcp\Security\Boot\Rules\ApiKeysPresent;
use SinaSaeedi\RuntimeMcp\Tests\Support\Users;
use SinaSaeedi\RuntimeMcp\Tests\TestCase;

/*
 * Spec 7.3, 01-12 Part B: each core check, run directly after bootWith(). runtime-mcp.enabled stays
 * false (bootWith's default), so no boot rule throws while the app boots; the BootRules check still
 * runs every rule.
 */

/**
 * @param  class-string<Check>  $class
 * @return list<array{string, string, string|null}> [status, message, fix]
 */
function runCoreCheck(string $class): array
{
    // make() with a class-string variable is mixed for Larastan; a literal ::class would not be.
    $check = app()->make($class);
    assert($check instanceof Check);

    return array_map(
        fn (CheckResult $result): array => [$result->status->value, $result->message, $result->fix],
        $check->run(),
    );
}

it('Endpoint: passes when enabled is exactly true, and warns otherwise', function (mixed $enabled, string $status) {
    currentTest()->bootWith(['runtime-mcp.enabled' => $enabled]);

    expect(runCoreCheck(Endpoint::class)[0][0])->toBe($status);
})->with([
    'true' => [true, 'pass'],
    'false' => [false, 'warn'],
    'the string true' => ['true', 'warn'],
]);

it('Endpoint: says the endpoint is off and that the other checks still run', function () {
    currentTest()->bootWith(['runtime-mcp.enabled' => false]);

    expect(runCoreCheck(Endpoint::class))->toBe([[
        'warn',
        'The endpoint is off (RUNTIME_MCP_ENABLED is not true).',
        'Set RUNTIME_MCP_ENABLED=true when agents may connect. The other checks still run.',
    ]]);
});

it('Boot rules: one pass when every rule passes', function () {
    currentTest()->bootWith([], 'production');

    expect(runCoreCheck(BootRules::class))->toBe([['pass', 'Every boot rule passes.', null]]);
});

it('Boot rules: runs the rules while the endpoint is off, one fail per error, with the rule id', function () {
    currentTest()->bootWith(['runtime-mcp.enabled' => false, 'runtime-mcp.auth.api_keys' => null], 'production');

    expect(runCoreCheck(BootRules::class))->toBe([['fail', '`api_keys.present`: '.ApiKeysPresent::ERROR, null]]);
});

it('Boot rules: a warning issue is a warn, in rule order after the errors before it', function () {
    currentTest()->bootWith(['runtime-mcp.auth.api_keys' => 'sina:abc', 'runtime-mcp.database.connection' => null], 'local');

    expect(runCoreCheck(BootRules::class))->toBe([
        ['fail', '`api_keys.format`: RUNTIME_MCP_API_KEYS entry 1 is not in the name:sha256hex format.', null],
        ['warn', '`database.connection`: RUNTIME_MCP_DB_CONNECTION is not set. The `models` and `database` plugins need a separate read-only connection.', null],
    ]);
});

it('Auth driver: passes for api_key', function () {
    currentTest()->bootWith();

    expect(runCoreCheck(AuthDriver::class))->toBe([['pass', 'auth.driver is `api_key`.', null]]);
});

it('Auth driver: fails for any other driver, and prints only a name-like value', function (mixed $driver, string $shown) {
    currentTest()->bootWith(['runtime-mcp.auth.driver' => $driver]);

    expect(runCoreCheck(AuthDriver::class))->toBe([[
        'fail',
        "auth.driver is {$shown}, so every request is refused. This version supports only `api_key`.",
        'Set runtime-mcp.auth.driver to `api_key`.',
    ]]);
})->with([
    'sanctum (until 01-35)' => ['sanctum', '`sanctum`'],
    'passport' => ['passport', '`passport`'],
    'not a name' => ['Bad Driver!', 'not a driver name'],
    'not a string' => [5, 'not a driver name'],
    'null' => [null, 'not a driver name'],
]);

it('Linked users: passes with no link', function (mixed $links) {
    currentTest()->bootWith(['runtime-mcp.auth.link_users' => $links]);

    expect(runCoreCheck(LinkedUsers::class))->toBe([['pass', 'No key is linked to a user.', null]]);
})->with(['null' => [null], 'empty' => [[]]]);

it('Linked users: passes when every linked key finds its user', function () {
    currentTest()->bootWith(['runtime-mcp.auth.link_users' => ['sina' => 'sina@example.com', 'kate' => 'kate@example.com']]);
    Users::create('sina@example.com');
    Users::create('kate@example.com');

    expect(runCoreCheck(LinkedUsers::class))->toBe([['pass', '2 linked keys find their user.', null]]);
});

it('Linked users: one linked key is singular', function () {
    currentTest()->bootWith(['runtime-mcp.auth.link_users' => ['sina' => 'sina@example.com']]);
    Users::create('sina@example.com');

    expect(runCoreCheck(LinkedUsers::class))->toBe([['pass', '1 linked key finds its user.', null]]);
});

it('Linked users: fails for a missing user and names the key, never the email', function () {
    currentTest()->bootWith(['runtime-mcp.auth.link_users' => ['sina' => 'sina@example.com', 'kate' => 'nobody@example.com']]);
    Users::create('sina@example.com');

    $results = runCoreCheck(LinkedUsers::class);

    expect($results)->toBe([[
        'fail',
        'Key `kate` is linked to a user that does not exist, so calls with this key are rejected.',
        'Create the user, or fix the email in auth.link_users.',
    ]])
        ->and(str_contains(json_encode($results, JSON_THROW_ON_ERROR), 'example.com'))->toBeFalse();
});

it('Linked users: fails for a link with no email', function (mixed $email) {
    currentTest()->bootWith(['runtime-mcp.auth.link_users' => ['sina' => $email]]);

    expect(runCoreCheck(LinkedUsers::class))->toBe([[
        'fail',
        'Key `sina` has no email, so calls with this key are rejected.',
        'Give the key a user email in auth.link_users, or remove the link.',
    ]]);
})->with(['empty' => [''], 'blank' => ['  '], 'not a string' => [5], 'null' => [null]]);

it('Linked users: names a link by position when its key is not a key name', function () {
    currentTest()->bootWith(['runtime-mcp.auth.link_users' => ['bad name!' => '']]);

    expect(runCoreCheck(LinkedUsers::class)[0][1])->toBe('The link at position 1 has no email, so calls with this key are rejected.');
});

it('Linked users: fails when link_users is not an array', function () {
    currentTest()->bootWith(['runtime-mcp.auth.link_users' => 'sina=sina@example.com']);

    expect(runCoreCheck(LinkedUsers::class))->toBe([[
        'fail',
        'auth.link_users is not an array, so every key is rejected.',
        'Set auth.link_users to an array of key name => user email, or to [].',
    ]]);
});

it('Linked users: reports a broken user provider once, with its class only', function () {
    // No users table: the provider throws a QueryException for every link.
    currentTest()->bootWith(['runtime-mcp.auth.link_users' => ['sina' => 'sina@example.com', 'kate' => 'kate@example.com']]);

    expect(runCoreCheck(LinkedUsers::class))->toBe([[
        'fail',
        'The user provider could not run (Illuminate\Database\QueryException), so every linked key is rejected.',
        'Check auth.defaults.guard, its user provider, and that the users table exists.',
    ]]);
});

it('STDIO principal: passes when unset or a valid name', function (mixed $principal, string $message) {
    currentTest()->bootWith(['runtime-mcp.auth.stdio_principal' => $principal]);

    expect(runCoreCheck(StdioPrincipal::class))->toBe([['pass', $message, null]]);
})->with([
    'null' => [null, 'RUNTIME_MCP_STDIO_PRINCIPAL is not set, so the STDIO server does not start.'],
    'empty' => ['', 'RUNTIME_MCP_STDIO_PRINCIPAL is not set, so the STDIO server does not start.'],
    'a name' => ['local-dev', 'The STDIO server runs as `local-dev`.'],
]);

it('STDIO principal: fails for a value that is not a name, and never prints it', function (mixed $principal) {
    currentTest()->bootWith(['runtime-mcp.auth.stdio_principal' => $principal]);

    $results = runCoreCheck(StdioPrincipal::class);

    expect($results)->toBe([[
        'fail',
        'RUNTIME_MCP_STDIO_PRINCIPAL is not a valid name, so the STDIO server does not start.',
        'Use 1 to 64 letters, digits, dots, dashes or underscores, as for a key name.',
    ]]);
})->with(['spaces' => ['local dev'], 'too long' => [str_repeat('a', 65)], 'not a string' => [123]]);

it('STDIO principal: fails for a value that looks like an API key, and never prints it', function () {
    // A key is a valid name (rmcp_ plus 43 base64url characters): without this check it would pass.
    currentTest()->bootWith(['runtime-mcp.auth.stdio_principal' => TestCase::TEST_KEY]);

    expect(runCoreCheck(StdioPrincipal::class))->toBe([[
        'fail',
        'RUNTIME_MCP_STDIO_PRINCIPAL looks like an API key. The principal name is written to every audit entry.',
        'Set it to a name such as `local-dev`, and replace the key if it was shared.',
    ]]);
});

it('Route: passes with a path and valid middleware', function () {
    currentTest()->bootWith();

    expect(runCoreCheck(Route::class))->toBe([['pass', 'The MCP route is `/mcp`.', null]]);
});

it('Route: fails for a bad path or bad middleware, one line each (Sina, 01-10 Q2)', function (array $config, array $messages) {
    /** @var array<string, mixed> $config */
    currentTest()->bootWith($config);

    expect(array_column(runCoreCheck(Route::class), 1))->toBe($messages)
        ->and(array_unique(array_column(runCoreCheck(Route::class), 0)))->toBe(['fail']);
})->with([
    'empty path' => [['runtime-mcp.path' => '/'], ['runtime-mcp.path is not a non-empty string, so no MCP route is registered.']],
    'path not a string' => [['runtime-mcp.path' => 5], ['runtime-mcp.path is not a non-empty string, so no MCP route is registered.']],
    'middleware a string' => [['runtime-mcp.middleware' => 'auth'], ['runtime-mcp.middleware is not a list of non-empty strings, so no MCP route is registered.']],
    'middleware with an empty entry' => [['runtime-mcp.middleware' => ['auth', '']], ['runtime-mcp.middleware is not a list of non-empty strings, so no MCP route is registered.']],
    'both' => [['runtime-mcp.path' => '', 'runtime-mcp.middleware' => 'auth'], [
        'runtime-mcp.path is not a non-empty string, so no MCP route is registered.',
        'runtime-mcp.middleware is not a list of non-empty strings, so no MCP route is registered.',
    ]],
]);

it('Allowed hosts: passes and says where the hosts come from', function (array $config, string $message) {
    /** @var array<string, mixed> $config */
    currentTest()->bootWith($config);

    expect(runCoreCheck(AllowedHosts::class))->toBe([['pass', $message, null]]);
})->with([
    'APP_URL' => [[], '1 host is allowed (the host of APP_URL).'],
    'a list' => [['runtime-mcp.allowed_hosts' => ['a.test', 'b.test:8443']], '2 hosts are allowed (runtime-mcp.allowed_hosts).'],
]);

it('Allowed hosts: fails when the resolved list is empty', function (array $config) {
    /** @var array<string, mixed> $config */
    currentTest()->bootWith($config);

    expect(runCoreCheck(AllowedHosts::class))->toBe([['fail', 'Every request is refused.', 'Set APP_URL or runtime-mcp.allowed_hosts.']]);
})->with([
    'no APP_URL' => [['app.url' => '']],
    'APP_URL with no scheme' => [['app.url' => 'app.test']],
    'only bad entries' => [['runtime-mcp.allowed_hosts' => ['bad host!', 'a.test:99999']]],
    'an empty list' => [['runtime-mcp.allowed_hosts' => []]],
]);

it('Allowed hosts: says so first when allowed_hosts is not a list', function (mixed $hosts) {
    currentTest()->bootWith(['runtime-mcp.allowed_hosts' => $hosts]);

    expect(runCoreCheck(AllowedHosts::class))->toBe([[
        'fail',
        'runtime-mcp.allowed_hosts is not a list, so every request is refused.',
        'Set it to a list of host or host:port names, or to null for the host of APP_URL.',
    ]]);
})->with(['a string' => ['app.test'], 'a map' => [['main' => 'app.test']]]);

it('Allowed origins: passes for none or valid origins', function (mixed $origins, string $message) {
    currentTest()->bootWith(['runtime-mcp.allowed_origins' => $origins]);

    expect(runCoreCheck(AllowedOrigins::class))->toBe([['pass', $message, null]]);
})->with([
    'none' => [[], 'No browser origin is allowed. Clients without an Origin header are not affected.'],
    'one' => [['https://admin.example.com'], '1 browser origin is allowed.'],
    'two' => [['https://admin.example.com', 'http://localhost:5173/'], '2 browser origins are allowed.'],
]);

it('Allowed origins: fails per bad entry, by 1-based position, never the value', function () {
    currentTest()->bootWith(['runtime-mcp.allowed_origins' => ['https://ok.example', 'secret-value', 5, 'https://a.example/path']]);

    $results = runCoreCheck(AllowedOrigins::class);

    expect(array_column($results, 1))->toBe([
        'allowed_origins entry 2 is not scheme://host[:port], so it never matches.',
        'allowed_origins entry 3 is not scheme://host[:port], so it never matches.',
        'allowed_origins entry 4 is not scheme://host[:port], so it never matches.',
    ])
        ->and($results[0][2])->toBe('Write it as https://example.com or https://example.com:8443, with no path.')
        ->and(str_contains(json_encode($results, JSON_THROW_ON_ERROR), 'secret-value'))->toBeFalse();
});

it('Allowed origins: warns for null', function () {
    currentTest()->bootWith(['runtime-mcp.allowed_origins' => ['https://ok.example', 'null']]);

    expect(runCoreCheck(AllowedOrigins::class))->toBe([[
        'warn',
        'allowed_origins lists `null`, so sandboxed pages and local files can reach the endpoint.',
        'Remove `null` from runtime-mcp.allowed_origins.',
    ]]);
});

it('Allowed origins: fails when allowed_origins is not a list', function (mixed $origins) {
    currentTest()->bootWith(['runtime-mcp.allowed_origins' => $origins]);

    expect(runCoreCheck(AllowedOrigins::class))->toBe([[
        'fail',
        'runtime-mcp.allowed_origins is not a list, so every browser origin is refused.',
        'Set it to a list of origins such as https://admin.example.com, or to [].',
    ]]);
})->with(['a string' => ['https://ok.example'], 'a map' => [['admin' => 'https://ok.example']], 'null' => [null]]);

it('Redaction patterns: passes and counts the extra patterns', function (mixed $patterns, string $message) {
    currentTest()->bootWith(['runtime-mcp.redaction.extra_patterns' => $patterns]);

    expect(runCoreCheck(RedactionPatterns::class))->toBe([['pass', $message, null]]);
})->with([
    'none' => [[], 'The built-in patterns apply.'],
    'null' => [null, 'The built-in patterns apply.'],
    'one' => [['/ORD-[0-9]+/'], 'The built-in patterns and 1 extra pattern apply.'],
    'two' => [['/ORD-[0-9]+/', ['pattern' => '/INV-[0-9]+/', 'type' => 'invoice']], 'The built-in patterns and 2 extra patterns apply.'],
]);

it('Redaction patterns: fails for the first bad entry, 1-based, with a fix per reason, never the pattern', function (array $patterns, string $entry, string $fix) {
    currentTest()->bootWith(['runtime-mcp.redaction.extra_patterns' => $patterns]);

    $results = runCoreCheck(RedactionPatterns::class);

    expect($results)->toBe([['fail', "Every tool call fails until redaction.extra_patterns entry {$entry} is fixed.", $fix]])
        ->and(str_contains(json_encode($results, JSON_THROW_ON_ERROR), 'unclosed'))->toBeFalse();
})->with([
    'does not compile' => [['/ok/', '/(unclosed/'], '2', 'Fix the regex; test it with preg_match().'],
    'not an entry' => [['/ok/', 5], '2', "Use a regex string, or ['pattern' => '/…/', 'type' => 'name']."],
    'bad type' => [[['pattern' => '/x/', 'type' => 'Bad Type']], '1', 'Use a type of lower-case letters, digits and _, at most 32, that starts with a letter.'],
    'a string key' => [['orders' => '/(unclosed/'], '`orders`', 'Fix the regex; test it with preg_match().'],
]);

it('Redaction patterns: warns when extra_patterns is not an array', function () {
    currentTest()->bootWith(['runtime-mcp.redaction.extra_patterns' => '/ORD-[0-9]+/']);

    expect(runCoreCheck(RedactionPatterns::class))->toBe([[
        'warn',
        'redaction.extra_patterns is not an array, so no extra pattern applies.',
        'Set it to a list of patterns, or to [].',
    ]]);
});

it('Mask lists: passes for the default lists', function () {
    currentTest()->bootWith();

    expect(runCoreCheck(MaskLists::class))->toBe([['pass', 'masked_columns and denied_tables are lists of names.', null]]);
});

it('Mask lists: warns with the count of ignored entries, never the values', function () {
    currentTest()->bootWith([
        'runtime-mcp.database.masked_columns' => ['password', 123],
        'runtime-mcp.database.denied_tables' => ['sessions', '', 'a.b.c'],
    ]);

    $results = runCoreCheck(MaskLists::class);

    expect($results)->toBe([
        ['warn', 'masked_columns has 1 entry that is not a column name. It is ignored.', 'Write each entry as a non-empty string.'],
        ['warn', 'denied_tables has 2 entries that are not a table name. They are ignored.', 'Write each entry as a non-empty string.'],
    ])
        ->and(str_contains(json_encode($results, JSON_THROW_ON_ERROR), 'a.b.c'))->toBeFalse();
});

it('Mask lists: fails when a list is not an array (01-11, Sina 2026-10-19)', function (array $config, array $messages) {
    /** @var array<string, mixed> $config */
    currentTest()->bootWith($config);

    $results = runCoreCheck(MaskLists::class);

    expect(array_column($results, 1))->toBe($messages)
        ->and(array_unique(array_column($results, 0)))->toBe(['fail'])
        ->and($results[0][2])->toBe('Set it to a list of names, as in the published config file.');
})->with([
    'masked_columns a string' => [['runtime-mcp.database.masked_columns' => 'password'], ['runtime-mcp.database.masked_columns must be an array of names. No role SQL was generated.']],
    'denied_tables null' => [['runtime-mcp.database.denied_tables' => null], ['runtime-mcp.database.denied_tables must be an array of names. No role SQL was generated.']],
    'both' => [['runtime-mcp.database.masked_columns' => 'password', 'runtime-mcp.database.denied_tables' => null], [
        'runtime-mcp.database.masked_columns must be an array of names. No role SQL was generated.',
        'runtime-mcp.database.denied_tables must be an array of names. No role SQL was generated.',
    ]],
]);

it('Plugins: passes and names the active plugins', function () {
    currentTest()->bootWith(['runtime-mcp.plugins.queue' => false]);

    expect(runCoreCheck(Plugins::class))->toBe([['pass', 'Active: core, models, database, logs.', null]]);
});

it('Plugins: says so when no plugin is active', function () {
    currentTest()->bootWith(['runtime-mcp.plugins' => ['core' => false, 'models' => false, 'database' => false, 'queue' => false, 'logs' => false]]);

    expect(runCoreCheck(Plugins::class))->toBe([['pass', 'No plugin is active.', null]]);
});

it('Plugins: warns per unknown key, and prints only a name-like key', function () {
    currentTest()->bootWith(['runtime-mcp.plugins.horizon' => true, 'runtime-mcp.plugins.Bad Key!' => true]);

    expect(runCoreCheck(Plugins::class))->toBe([
        ['warn', 'No plugin named `horizon` in this version.', 'Remove the key, or update the package if the plugin is newer.'],
        ['warn', 'runtime-mcp.plugins has a key that no plugin in this version owns.', 'Remove the key, or update the package if the plugin is newer.'],
    ]);
});

it('Plugins: warns when plugins is not an array (01-03: every plugin is off)', function () {
    currentTest()->bootWith(['runtime-mcp.plugins' => 'core']);

    expect(runCoreCheck(Plugins::class))->toBe([[
        'warn',
        'runtime-mcp.plugins is not an array, so every plugin is off.',
        'Set runtime-mcp.plugins to an array of plugin name => true or false.',
    ]]);
});

it('Audit log: passes for the channel of the package and names the directory', function () {
    currentTest()->bootWith();

    expect(runCoreCheck(AuditLog::class))->toBe([['pass', 'Channel `runtime-mcp` writes to '.storage_path('logs').'.', null]]);
});

it('Audit log: passes for an app channel with no path', function () {
    currentTest()->bootWith(['logging.channels.runtime-mcp' => ['driver' => 'stack', 'channels' => ['single']]]);

    expect(runCoreCheck(AuditLog::class))->toBe([['pass', "The app's channel `runtime-mcp` writes the audit.", null]]);
});

it('Audit log: checks the nearest directory that exists', function () {
    currentTest()->bootWith(['logging.channels.runtime-mcp' => ['driver' => 'daily', 'path' => storage_path('logs/not/yet/audit.log')]]);

    expect(runCoreCheck(AuditLog::class))->toBe([['pass', 'Channel `runtime-mcp` writes to '.storage_path('logs').'.', null]]);
});

it('Audit log: fails when the directory is not writable', function () {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        currentTest()->markTestSkipped('root can write to every directory');
    }

    $directory = sys_get_temp_dir().'/runtime-mcp-doctor-'.bin2hex(random_bytes(4));
    mkdir($directory, 0555);

    try {
        currentTest()->bootWith(['logging.channels.runtime-mcp' => ['driver' => 'single', 'path' => $directory.'/audit.log']]);

        expect(runCoreCheck(AuditLog::class))->toBe([[
            'fail',
            "The audit cannot be written to {$directory}, so tool results are withheld.",
            'Make the directory writable for the user that runs PHP.',
        ]]);
    } finally {
        chmod($directory, 0755);
        rmdir($directory);
    }
});

it('Audit log: fails when the channel is not defined', function () {
    currentTest()->bootWith();
    config()->set('logging.channels.runtime-mcp', null);

    expect(runCoreCheck(AuditLog::class))->toBe([[
        'fail',
        'Log channel `runtime-mcp` is not defined, so tool results are withheld.',
        'Define logging.channels.runtime-mcp, or remove it from config/logging.php so the package registers its own.',
    ]]);
});

it('Audit log: warns when audit.channel or audit.days falls back (01-05)', function (array $config, string $message) {
    /** @var array<string, mixed> $config */
    currentTest()->bootWith($config);

    $results = runCoreCheck(AuditLog::class);

    expect(array_column($results, 0))->toBe(['warn', 'pass'])
        ->and($results[0][1])->toBe($message);
})->with([
    'a dotted channel name' => [['runtime-mcp.audit.channel' => 'runtime.mcp'], 'runtime-mcp.audit.channel is not a valid channel name, so the audit uses `runtime-mcp`.'],
    'zero days' => [['runtime-mcp.audit.days' => 0], 'runtime-mcp.audit.days is not a whole number of 1 or more, so 90 is used.'],
    'days not a number' => [['runtime-mcp.audit.days' => 'ninety'], 'runtime-mcp.audit.days is not a whole number of 1 or more, so 90 is used.'],
]);

it('Audit log: accepts days as an env() string of digits', function () {
    currentTest()->bootWith(['runtime-mcp.audit.days' => '30']);

    expect(array_column(runCoreCheck(AuditLog::class), 0))->toBe(['pass']);
});

it('every core result has a fix unless it passes', function () {
    currentTest()->bootWith([
        'runtime-mcp.enabled' => false,
        'runtime-mcp.auth.driver' => 'nope',
        'runtime-mcp.auth.link_users' => 'x',
        'runtime-mcp.auth.stdio_principal' => 'bad name',
        'runtime-mcp.path' => '',
        'runtime-mcp.allowed_hosts' => [],
        'runtime-mcp.allowed_origins' => ['x'],
        'runtime-mcp.redaction.extra_patterns' => ['/(/'],
        'runtime-mcp.database.masked_columns' => 'x',
        'runtime-mcp.plugins.horizon' => true,
    ]);

    foreach ([Endpoint::class, AuthDriver::class, LinkedUsers::class, StdioPrincipal::class, Route::class, AllowedHosts::class, AllowedOrigins::class, RedactionPatterns::class, MaskLists::class, Plugins::class] as $class) {
        foreach (runCoreCheck($class) as [$status, , $fix]) {
            expect($status)->not->toBe(CheckStatus::Pass->value, $class)
                ->and($fix)->not->toBeNull($class);
        }
    }
});
