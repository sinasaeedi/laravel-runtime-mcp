<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\PendingCommand;
use SinaSaeedi\RuntimeMcp\Console\InstallCommand;
use SinaSaeedi\RuntimeMcp\Console\KeyCommand;
use SinaSaeedi\RuntimeMcp\Plugins\Contracts\Plugin;
use SinaSaeedi\RuntimeMcp\Plugins\Core\CorePlugin;
use SinaSaeedi\RuntimeMcp\Plugins\PluginRegistry;
use SinaSaeedi\RuntimeMcp\Security\Auth\KeyIssuer;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Plugins\FakeInstalledPlugin;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Plugins\FakeOptionalPlugin;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\CommandTrail;

/*
 * Spec 7.1 (01-28): php artisan runtime-mcp:install on the test app, whose default connection is SQLite. The SQL
 * step on PostgreSQL, MySQL and MariaDB is in tests/Integration/{Pgsql,Mysql}/InstallCommandTest.php. vendor:publish writes into
 * the Testbench skeleton, and a file left there replaces the package config in every later boot: every test deletes
 * it before and after. Every Artisan::call() passes --no-interaction: an ArrayInput is interactive, and the key
 * question would read STDIN. For "does not contain", use str_contains() (01-04 trap). Helpers start with
 * "installCommand".
 */

function installCommandConfigFile(): string
{
    return config_path('runtime-mcp.php');
}

/**
 * @param  array<string, mixed>  $options
 * @return array{int, string} the exit code and the whole output
 */
function installCommandRun(array $options = []): array
{
    $code = Artisan::call(InstallCommand::NAME, ['--no-interaction' => true, ...$options]);

    return [$code, Artisan::output()];
}

/**
 * The non-empty lines, trimmed, with the WARN and ERROR labels of Laravel's components cut to one word.
 *
 * @return list<string>
 */
function installCommandLines(string $output): array
{
    $lines = [];

    foreach (explode("\n", $output) as $line) {
        $line = (string) preg_replace('/^(WARN|ERROR)\s+/', '$1 ', trim($line));

        if ($line !== '') {
            $lines[] = $line;
        }
    }

    return $lines;
}

/** The one key in the output. */
function installCommandKey(string $output): string
{
    expect(preg_match_all('/rmcp_[A-Za-z0-9_-]{43}(?![A-Za-z0-9_-])/', $output, $matches))->toBeGreaterThan(0);
    $keys = array_values(array_unique($matches[0]));
    expect($keys)->toHaveCount(1);

    return $keys[0];
}

/** An interactive run (no --no-interaction), for the question tests. */
function installCommandAsk(): PendingCommand
{
    $pending = currentTest()->artisan(InstallCommand::NAME);

    if (! $pending instanceof PendingCommand) {
        throw new LogicException('mockConsoleOutput is off.');
    }

    return $pending;
}

/** @param  list<class-string<Plugin>>  $plugins */
function installCommandPlugins(array $plugins): void
{
    app()->scoped(PluginRegistry::class, fn (Application $app): PluginRegistry => new PluginRegistry(
        $app, $app->make(Repository::class), $plugins,
    ));
}

beforeEach(function () {
    @unlink(installCommandConfigFile());
    AuditLog::clear();
});

afterEach(function () {
    @unlink(installCommandConfigFile());
    AuditLog::clear();
});

it('IN1 publishes the config file once, and keeps it as it is on the next run', function () {
    [$first, $output] = installCommandRun();

    expect($first)->toBe(0)
        ->and(installCommandConfigFile())->toBeFile()
        ->and(file_get_contents(installCommandConfigFile()))->toBe(file_get_contents(dirname(__DIR__, 3).'/config/runtime-mcp.php'))
        ->and($output)->toContain(InstallCommand::CONFIG_PUBLISHED)
        ->and(str_contains($output, InstallCommand::CONFIG_KEPT))->toBeFalse();

    // The operator's own file is never overwritten (no --force).
    file_put_contents(installCommandConfigFile(), "<?php\n\nreturn ['enabled' => false];\n");
    $before = sha1_file(installCommandConfigFile());

    [$second, $output] = installCommandRun();

    expect($second)->toBe(0)
        ->and(sha1_file(installCommandConfigFile()))->toBe($before)
        ->and($output)->toContain(InstallCommand::CONFIG_KEPT)
        ->and(str_contains($output, InstallCommand::CONFIG_PUBLISHED))->toBeFalse();
});

it('IN2 prints the five steps in order on a SQLite app, with no SQL, no key and exit 0', function () {
    [$code, $output] = installCommandRun();

    expect($code)->toBe(0)->and(installCommandLines($output))->toBe([
        'Step 1 of 5: the config file',
        InstallCommand::CONFIG_PUBLISHED,
        'Step 2 of 5: the read-only role',
        'WARN The read-only role SQL supports PostgreSQL, MySQL and MariaDB; this app uses sqlite. The `models`, `database` and `queue` plugins need a read-only connection on one of them. Turn them off in config/runtime-mcp.php (\'models\' => false, \'database\' => false, \'queue\' => false), or use PostgreSQL, MySQL or MariaDB.',
        'Step 3 of 5: the first key',
        InstallCommand::KEY_LATER,
        InstallCommand::SANCTUM_HINT,
        'Step 4 of 5: plugins',
        InstallCommand::NO_PLUGIN,
        'Step 5 of 5: next steps',
        '1. '.InstallCommand::NEXT_UNSUPPORTED,
        '2. '.InstallCommand::NEXT_ENABLED,
        '3. '.InstallCommand::NEXT_KEY_LATER,
        '4. '.InstallCommand::NEXT_GATES,
        '5. '.InstallCommand::NEXT_DOCTOR,
        '6. '.InstallCommand::NEXT_CONNECT_LATER,
    ])->and(str_contains($output, InstallCommand::SQL_BEGIN))->toBeFalse()
        ->and(str_contains($output, InstallCommand::CONNECTION_BEGIN))->toBeFalse()
        ->and(str_contains($output, InstallCommand::ENV_BEGIN))->toBeFalse();
});

it('IN3 names only the plugins that need the read-only connection, or none, on a SQLite app', function (array $plugins, string $expected) {
    currentTest()->bootWith(['runtime-mcp.plugins' => $plugins]);

    [$code, $output] = installCommandRun();

    expect($code)->toBe(0)
        ->and(installCommandLines($output)[3])->toBe('WARN '.$expected);
})->with([
    'only models' => [['core' => true, 'models' => true, 'database' => false, 'queue' => false, 'logs' => true],
        'The read-only role SQL supports PostgreSQL, MySQL and MariaDB; this app uses sqlite. The `models` plugin needs a read-only connection on one of them. Turn it off in config/runtime-mcp.php (\'models\' => false), or use PostgreSQL, MySQL or MariaDB.'],
    'none' => [['core' => true, 'models' => false, 'database' => false, 'queue' => false, 'logs' => true],
        'The read-only role SQL supports PostgreSQL, MySQL and MariaDB; this app uses sqlite.'],
]);

it('IN4 reports a malformed mask list before the driver, prints no SQL, runs the later steps and exits 1', function (string $key, mixed $value) {
    config(["runtime-mcp.database.{$key}" => $value]);

    [$code, $output] = installCommandRun(['--key-name' => 'sina']);

    expect($code)->toBe(1)
        ->and($output)->toContain("runtime-mcp.database.{$key} must be an array of names. No role SQL was generated.")
        ->and(str_contains($output, 'this app uses sqlite'))->toBeFalse()
        ->and(str_contains($output, InstallCommand::SQL_BEGIN))->toBeFalse()
        ->and($output)->toContain('Key for "sina"')
        ->and($output)->toContain('Step 5 of 5: next steps')
        ->and($output)->toContain('1. '.InstallCommand::NEXT_FIX_ROLE);
})->with([
    'masked_columns as a string' => ['masked_columns', 'password'],
    'denied_tables null' => ['denied_tables', null],
]);

it('IN5 runs runtime-mcp:key for --key-name, with its exact output, between steps 3 and 4', function () {
    [$code, $output] = installCommandRun(['--key-name' => 'sina']);
    $key = installCommandKey($output);
    $step3 = strpos($output, 'Step 3 of 5');
    $step4 = strpos($output, 'Step 4 of 5');
    $keyAt = strpos($output, 'Key for "sina"');

    expect($code)->toBe(0)
        ->and($step3)->toBeInt()->and($step4)->toBeInt()->and($keyAt)->toBeInt()
        ->and($keyAt > $step3 && $keyAt < $step4)->toBeTrue()
        ->and($output)->toContain('RUNTIME_MCP_API_KEYS=sina:'.hash('sha256', $key))
        ->and($output)->toContain('claude mcp add --transport http runtime-test-testing https://app.test/mcp')
        ->and(str_contains($output, InstallCommand::KEY_LATER))->toBeFalse()
        ->and($output)->toContain('3. '.InstallCommand::NEXT_KEY_MADE)
        ->and($output)->toContain('6. '.InstallCommand::NEXT_CONNECT_MADE);
});

it('IN6 passes a refused key name to runtime-mcp:key, runs steps 4 and 5, and exits 1', function (string $name, string $message) {
    [$code, $output] = installCommandRun(['--key-name' => $name]);

    expect($code)->toBe(1)
        ->and($output)->toContain($message)
        ->and(preg_match('/rmcp_[A-Za-z0-9_-]{43}/', $output))->toBe(0)
        ->and($output)->toContain(InstallCommand::NO_PLUGIN)
        ->and($output)->toContain('3. '.InstallCommand::NEXT_KEY_LATER);
})->with([
    'a space' => ['bad name', KeyIssuer::NAME_RULE],
    'the key prefix' => ['rmcp_bot', KeyIssuer::PREFIX_RULE],
    'empty' => ['', KeyIssuer::NAME_RULE],
]);

it('IN7 asks first, then asks for the name, in an interactive run', function () {
    installCommandAsk()
        ->expectsConfirmation(InstallCommand::KEY_CONFIRM, 'yes')
        ->expectsQuestion(InstallCommand::KEY_QUESTION, 'ada')
        ->expectsOutputToContain('Key for "ada"')
        ->expectsOutputToContain(InstallCommand::NEXT_KEY_MADE)
        ->assertExitCode(0);
});

it('IN8 makes no key when the operator says no', function () {
    installCommandAsk()
        ->expectsConfirmation(InstallCommand::KEY_CONFIRM, 'no')
        ->expectsOutputToContain(InstallCommand::KEY_LATER)
        ->doesntExpectOutputToContain('Key for')
        ->assertExitCode(0);
});

it('IN9 skips the key with the sanctum driver, also with --key-name, and points to runtime-mcp:token', function () {
    config(['runtime-mcp.auth.driver' => 'sanctum']);

    [$code, $output] = installCommandRun(['--key-name' => 'sina']);

    expect($code)->toBe(0)
        ->and($output)->toContain(InstallCommand::SANCTUM_KEY_STEP)
        ->and(str_contains($output, 'Key for'))->toBeFalse()
        ->and(str_contains($output, InstallCommand::SANCTUM_HINT))->toBeFalse()
        ->and($output)->toContain('3. '.InstallCommand::NEXT_TOKEN)
        ->and($output)->toContain('6. '.InstallCommand::NEXT_CONNECT_LATER);
});

it('IN10 lets runtime-mcp:key refuse another driver, with exit 1 and no Sanctum hint', function () {
    config(['runtime-mcp.auth.driver' => 'passport']);

    [$code, $output] = installCommandRun(['--key-name' => 'sina']);

    expect($code)->toBe(1)
        ->and($output)->toContain(KeyCommand::OTHER_DRIVER)
        ->and(str_contains($output, InstallCommand::SANCTUM_HINT))->toBeFalse();
});

it('IN11 prints the Sanctum hint only when laravel/sanctum is installed', function () {
    app()->bind(InstallCommand::class, fn (): InstallCommand => new InstallCommand('acme/not-installed'));

    [$code, $output] = installCommandRun(['--key-name' => 'sina']);

    expect($code)->toBe(0)
        ->and(str_contains($output, InstallCommand::SANCTUM_HINT))->toBeFalse()
        ->and(str_contains($output, 'laravel/sanctum'))->toBeFalse();
});

it('IN12 suggests a plugin whose package is installed and that is off, never a base plugin', function () {
    installCommandPlugins([CorePlugin::class, FakeInstalledPlugin::class, FakeOptionalPlugin::class]);

    [, $output] = installCommandRun();

    expect(substr_count($output, "`laravel/mcp` is installed. Enable its plugin with 'fake_installed' => true in runtime-mcp.plugins."))->toBe(1)
        ->and(str_contains($output, 'acme/not-installed'))->toBeFalse()
        ->and(str_contains($output, 'fake_optional'))->toBeFalse()
        ->and(str_contains($output, InstallCommand::NO_PLUGIN))->toBeFalse();

    config(['runtime-mcp.plugins.fake_installed' => true]);
    [, $enabled] = installCommandRun();

    expect($enabled)->toContain(InstallCommand::NO_PLUGIN)
        ->and(str_contains($enabled, 'fake_installed'))->toBeFalse();
});

it('IN13 never suggests a base plugin that the operator turned off', function () {
    currentTest()->bootWith(['runtime-mcp.plugins' => ['core' => true, 'models' => true, 'database' => true, 'queue' => true, 'logs' => false]]);

    [, $output] = installCommandRun();

    expect($output)->toContain(InstallCommand::NO_PLUGIN)
        ->and(str_contains($output, "'logs' => true"))->toBeFalse();
});

it('IN14 writes only the config file, logs nothing, defines no Gate, and no event holds the key', function () {
    $abilities = Gate::abilities();
    $trail = CommandTrail::start();

    [$code, $output] = installCommandRun(['--key-name' => 'sina']);
    $key = installCommandKey($output);

    expect($code)->toBe(0)
        ->and($trail->texts)->toContain('Illuminate\Console\Events\CommandFinished')
        ->and(array_map(fn (string $path): string|false => realpath($path), $trail->changedFiles()))->toBe([realpath(installCommandConfigFile())])
        ->and($trail->logged)->toBe([])
        ->and($trail->eventsHolding($key))->toBe([])
        ->and($trail->eventsHolding(substr($key, 5)))->toBe([])
        ->and(Gate::abilities())->toBe($abilities)
        ->and(AuditLog::files())->toBe([]);
});

it('IN15 prints the exception class only when the schema cannot be read, runs the later steps and exits 1', function () {
    currentTest()->bootWith([
        'database.default' => 'broken',
        // A socket folder that does not exist fails at once (127.0.0.1:1 can hang on WSL, 01-13).
        'database.connections.broken' => [
            'driver' => 'pgsql', 'host' => '/nonexistent/rmcp-socket', 'port' => '5432', 'database' => 'app',
            'username' => 'owner', 'password' => 'owner-secret', 'prefix' => '', 'search_path' => 'public',
        ],
    ]);

    [$code, $output] = installCommandRun(['--key-name' => 'sina']);

    expect($code)->toBe(1)
        ->and($output)->toContain('Could not read the tables of the default connection `broken` (Illuminate\Database\QueryException).')
        ->and(str_contains($output, 'owner-secret'))->toBeFalse()
        ->and(str_contains($output, 'nonexistent'))->toBeFalse()
        ->and(str_contains($output, InstallCommand::SQL_BEGIN))->toBeFalse()
        ->and($output)->toContain('Key for "sina"')
        ->and($output)->toContain('1. '.InstallCommand::NEXT_FIX_ROLE);
});

it('IN16 says to run config:cache again when the config is cached', function () {
    [, $plain] = installCommandRun();

    // LoadConfiguration records this binding when it loads bootstrap/cache/config.php; configurationIsCached() reads it.
    app()->instance('config_loaded_from_cache', true);

    [$code, $output] = installCommandRun();

    expect($code)->toBe(0)
        ->and($output)->toContain(InstallCommand::CONFIG_CACHED)
        // A cache older than the package has no runtime-mcp keys: steps 2 and 3 then fail for that reason.
        ->and(InstallCommand::CONFIG_CACHED)->toContain('php artisan config:clear')
        ->and(str_contains($plain, InstallCommand::CONFIG_CACHED))->toBeFalse();
});

it('IN17 offers a key name made from the script owner', function (string $user, string $expected) {
    expect(InstallCommand::defaultKeyName($user))->toBe($expected);
})->with([
    'a plain name' => ['sina', 'sina'],
    'a space and symbols' => ['Jane Doe!', 'JaneDoe'],
    'dots, dashes, underscores' => ['ci.bot_1-a', 'ci.bot_1-a'],
    'empty (php -r)' => ['', InstallCommand::FALLBACK_KEY_NAME],
    'only symbols' => ['***', InstallCommand::FALLBACK_KEY_NAME],
    'the key prefix' => ['rmcp_deploy', InstallCommand::FALLBACK_KEY_NAME],
    '70 letters' => [str_repeat('a', 70), str_repeat('a', 64)],
]);

it('IN18 writes a connection snippet that copies the settings, never the credentials or the url', function () {
    $default = [
        'driver' => 'pgsql', 'url' => 'pgsql://owner:pw@db/app', 'host' => 'db', 'port' => '5432', 'database' => 'app',
        'username' => 'owner', 'password' => 'pw', 'charset' => 'utf8', 'prefix' => "it's_", 'prefix_indexes' => true,
        'search_path' => ['app', 'public'], 'sslmode' => 'require', 'timezone' => '+00:00', 'pooled' => true,
        'read' => ['host' => ['replica']], 'write' => ['host' => ['db']], 'options' => [PDO::ATTR_PERSISTENT => true],
        'sslrootcert' => '/etc/ssl/ca.pem', 'sslcert' => '/etc/ssl/owner.crt', 'sslkey' => '/etc/ssl/owner.key',
        'direct' => ['host' => 'db-direct', 'password' => 'direct-pw'], 'schema' => 'old',
    ];

    expect(InstallCommand::snippet('pgsql', 'support_ro', $default))->toBe([
        "'runtime_mcp' => [",
        "    'driver' => 'pgsql',",
        "    'host' => env('DB_HOST', '127.0.0.1'),",
        "    'port' => env('DB_PORT', '5432'),",
        "    'database' => env('DB_DATABASE', 'laravel'),",
        "    'username' => env('RUNTIME_MCP_DB_USERNAME', 'support_ro'),",
        "    'password' => env('RUNTIME_MCP_DB_PASSWORD'),",
        '    // copied from your default connection:',
        "    'charset' => 'utf8',",
        "    'prefix' => 'it\\'s_',",
        "    'search_path' => ['app', 'public'],",
        "    'sslmode' => 'require',",
        "    'sslrootcert' => '/etc/ssl/ca.pem',",
        "    'timezone' => '+00:00',",
        '],',
    ])->and(InstallCommand::snippet('pgsql', 'r', ['schema' => 'app']))->toContain("    'search_path' => 'app',")
        ->and(InstallCommand::snippet('mariadb', 'runtime_mcp_ro', [
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'search_path' => 'public', 'timezone' => 5,
        ]))->toBe([
            "'runtime_mcp' => [",
            "    'driver' => 'mariadb',",
            "    'host' => env('DB_HOST', '127.0.0.1'),",
            "    'port' => env('DB_PORT', '3306'),",
            "    'database' => env('DB_DATABASE', 'laravel'),",
            "    'username' => env('RUNTIME_MCP_DB_USERNAME', 'runtime_mcp_ro'),",
            "    'password' => env('RUNTIME_MCP_DB_PASSWORD'),",
            "    'unix_socket' => env('DB_SOCKET', ''),",
            '    // copied from your default connection:',
            "    'charset' => 'utf8mb4',",
            "    'collation' => 'utf8mb4_unicode_ci',",
            "    'prefix' => '',",
            '],',
        ])->and(InstallCommand::snippet('pgsql', 'r', ['search_path' => ['a', 1]]))->not->toContain('    // copied from your default connection:')
        ->and(InstallCommand::snippet('pgsql', 'r', ['search_path' => ['schema' => 'a']]))->not->toContain('    // copied from your default connection:')
        ->and(InstallCommand::snippet('pgsql', 'r', ['search_path' => []]))->not->toContain('    // copied from your default connection:');
});

it('IN19 is registered next to the other commands', function () {
    $commands = Artisan::all();

    expect($commands)->toHaveKey(InstallCommand::NAME)
        ->and($commands[InstallCommand::NAME])->toBeInstanceOf(InstallCommand::class)
        ->and($commands)->toHaveKey(KeyCommand::NAME);
});

it('IN20 says so when the publish writes no file, runs the later steps and exits 1', function (string $publish) {
    // A vendor:publish that writes nothing, or that throws as the real one does on a config folder the user cannot
    // write (copy() warns, HandleExceptions makes it an ErrorException). Artisan builds its commands at the first
    // call, and a later command of the same name replaces the earlier one.
    Artisan::command('vendor:publish {--tag=*} {--force}', function () use ($publish): int {
        if ($publish === 'throws') {
            throw new ErrorException('copy(/app/config/runtime-mcp.php): Failed to open stream: Permission denied');
        }

        return 0;
    });

    [$code, $output] = installCommandRun(['--key-name' => 'sina']);

    expect($code)->toBe(1)
        ->and(installCommandConfigFile())->not->toBeFile()
        ->and($output)->toContain(InstallCommand::CONFIG_FAILED)
        ->and(str_contains($output, InstallCommand::CONFIG_PUBLISHED))->toBeFalse()
        ->and($output)->toContain('this app uses sqlite')
        ->and($output)->toContain('Key for "sina"')
        ->and($output)->toContain('Step 5 of 5: next steps')
        ->and(str_contains($output, 'Permission denied'))->toBeFalse();
})->with(['writes nothing' => ['returns'], 'throws' => ['throws']]);

it('IN21 reports a real config folder that the user cannot write, and runs the later steps', function () {
    $folder = dirname(installCommandConfigFile());
    $mode = fileperms($folder) & 0777;
    chmod($folder, 0555);

    try {
        // root writes anyway: then there is nothing to prove.
        if (is_writable($folder)) {
            currentTest()->markTestSkipped('The config folder stays writable (root).');
        }

        [$code, $output] = installCommandRun(['--key-name' => 'sina']);
    } finally {
        chmod($folder, $mode);
    }

    expect($code)->toBe(1)
        ->and(installCommandConfigFile())->not->toBeFile()
        ->and($output)->toContain(InstallCommand::CONFIG_FAILED)
        ->and($output)->toContain('Key for "sina"')
        ->and($output)->toContain('Step 5 of 5: next steps');
});

it('IN22 offers the fallback name when the host turned get_current_user() off, and runs the later steps (01-28 triage)', function () {
    // disable_functions removes a function, so function_exists() is false: the seam names one that does not exist.
    app()->bind(InstallCommand::class, fn (): InstallCommand => new InstallCommand(ownerFunction: 'rmcp_no_such_function'));

    installCommandAsk()
        ->expectsConfirmation(InstallCommand::KEY_CONFIRM, 'yes')
        ->expectsQuestion(InstallCommand::KEY_QUESTION, InstallCommand::FALLBACK_KEY_NAME)
        ->expectsOutputToContain('Key for "developer"')
        ->expectsOutputToContain('Step 5 of 5: next steps')
        ->assertExitCode(0);
});
