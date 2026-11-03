<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Support\Facades\Exceptions;
use SinaSaeedi\RuntimeMcp\Mcp\EnvelopeSchema;
use SinaSaeedi\RuntimeMcp\Plugins\Contracts\Plugin;
use SinaSaeedi\RuntimeMcp\Plugins\Core\CorePlugin;
use SinaSaeedi\RuntimeMcp\Plugins\Core\Tools\AppInfo;
use SinaSaeedi\RuntimeMcp\Plugins\PluginRegistry;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\QueuePlugin;
use SinaSaeedi\RuntimeMcp\Server\RuntimeServer;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\FixtureTools;
use SinaSaeedi\RuntimeMcp\Tests\Support\Gates;
use SinaSaeedi\RuntimeMcp\Tests\Support\JsonSchemaSubset;
use SinaSaeedi\RuntimeMcp\Tests\Support\ProtocolEra;
use SinaSaeedi\RuntimeMcp\Tests\TestCase;
use SinaSaeedi\RuntimeMcp\Tools\ToolInput;

/*
 * 01-18: app_info returns safe facts only (spec 6.1, 5.6), over HTTP with a valid key and a Gate
 * that allows the tool. No RefreshDatabase: a test transaction on the default connection makes
 * every read tool fail (01-16).
 */

beforeEach(fn () => AuditLog::clear());
afterEach(fn () => AuditLog::clear());

/**
 * Boots the package with the base plugins and allows app_info. The rate limiter keeps the array
 * store, so a test can point cache.default at a store that does not work.
 *
 * @param  array<string, mixed>  $config
 */
function appInfoServe(array $config = [], ?string $environment = null, bool $gate = true): void
{
    currentTest()->bootWith(['runtime-mcp.enabled' => true, 'cache.limiter' => 'array', ...$config], $environment);

    if ($gate) {
        Gates::allow('app_info');
    }
}

/**
 * The data of one app_info call over HTTP.
 *
 * @return array<array-key, mixed>
 */
function appInfoData(ProtocolEra $era = ProtocolEra::Legacy): array
{
    $envelope = FixtureTools::envelope(FixtureTools::call('app_info', era: $era));
    $data = $envelope['data'] ?? null;
    // A real check, not assert(): zend.assertions is -1 in a production php.ini, which compiles assert() out.
    expect($envelope['status'])->toBe('ok', 'app_info did not answer ok: '.json_encode($envelope));
    assert(is_array($data));

    return $data;
}

/**
 * The data of execute() called directly, without the pipeline (and without its redaction).
 *
 * @return array<array-key, mixed>
 */
function appInfoExecute(): array
{
    $data = app(AppInfo::class)->execute(new ToolInput([]))->data;
    assert(is_array($data));

    return $data;
}

/** The version of each spec 6.1 package as app_info must report it. */
function appInfoPackageVersion(string $package): ?string
{
    return InstalledVersions::isInstalled($package) ? InstalledVersions::getPrettyVersion($package) ?? 'unknown' : null;
}

const APP_INFO_PINNED = [
    'app.name' => 'Shop',
    'app.debug' => false,
    'cache.default' => 'main',
    'cache.stores.main' => ['driver' => 'redis', 'connection' => 'cache'],
    'queue.default' => 'jobs',
    'queue.connections.jobs' => ['driver' => 'redis', 'connection' => 'default', 'queue' => 'invoices'],
    'session.driver' => 'database',
    'mail.default' => 'outgoing',
    'mail.mailers.outgoing' => ['transport' => 'smtp', 'host' => 'smtp.example.test'],
    'logging.default' => 'single',
];

it('returns exactly the spec 6.1 shape for a pinned config', function (ProtocolEra $era) {
    appInfoServe(APP_INFO_PINNED, 'staging');

    $envelope = FixtureTools::envelope(FixtureTools::call('app_info', era: $era));
    $data = is_array($envelope['data'] ?? null) ? $envelope['data'] : [];

    expect(JsonSchemaSubset::violations($envelope, EnvelopeSchema::toArray()))->toBe([])
        ->and($envelope['status'])->toBe('ok')
        ->and($envelope['environment'])->toBe('staging')
        ->and(array_keys($data))->toBe(['app', 'versions', 'drivers', 'flags', 'warnings', 'plugins', 'packages'])
        ->and($data['app'])->toBe(['name' => 'Shop', 'environment' => 'staging', 'strict' => true])
        ->and($data['versions'])->toBe([
            'runtime_mcp' => app(RuntimeServer::class)->version(),
            'laravel' => app()->version(),
            'php' => PHP_VERSION,
        ])
        ->and($data['drivers'])->toBe([
            'cache' => 'redis', 'queue' => 'redis', 'session' => 'database', 'mail' => 'smtp',
            'database' => 'sqlite', 'log_channel' => 'single',
        ])
        ->and($data['flags'])->toBe([
            'debug' => false, 'maintenance_mode' => false, 'config_cached' => false, 'routes_cached' => false,
        ])
        ->and($data['warnings'])->toBe([])
        ->and($data['plugins'])->toBe(array_map(
            fn (Plugin $plugin): array => ['name' => $plugin->name(), 'description' => $plugin->description()],
            app(PluginRegistry::class)->all(),
        ))
        ->and(array_column(is_array($data['plugins']) ? $data['plugins'] : [], 'name'))->toBe(['core', 'models', 'database', 'queue', 'logs'])
        ->and(array_keys(is_array($data['packages']) ? $data['packages'] : []))->toBe(AppInfo::PACKAGES);
})->with([ProtocolEra::Legacy, ProtocolEra::Modern]);

it('names the app as the server name does, Laravel when app.name is empty', function (mixed $name, string $expected) {
    appInfoServe(['app.name' => $name]);

    expect(appInfoData()['app'])->toMatchArray(['name' => $expected])
        ->and(app(RuntimeServer::class)->name())->toBe($expected.' (testing)');
})->with([
    'null' => [null, 'Laravel'],
    'an empty string' => ['', 'Laravel'],
    'not a string' => [['Shop'], 'Laravel'],
    'a name' => ['Shop EU', 'Shop EU'],
]);

it('never returns a configured secret, URL or credential', function () {
    $planted = [
        'app.key' => 'PLANTED-APPKEY-7f3a',
        'database.connections.testing.password' => 'PLANTED-DBPASS-2c9e',
        'mail.mailers.smtp.password' => 'PLANTED-MAILPASS-5d1b',
        'database.redis.default.password' => 'PLANTED-REDISPASS-8a4f',
        'services.stripe.secret' => 'PLANTED-STRIPE-6e2c',
        'logging.channels.stack.url' => 'https://planted-webhook-1d7a.test/hook',
    ];
    $keyName = 'PLANTED-KEYNAME-3b7d';

    appInfoServe([
        ...$planted,
        'app.url' => 'https://planted-host-9c1e.test',
        'runtime-mcp.auth.api_keys' => TestCase::defaultKeyEntry().','.$keyName.':'.str_repeat('ab', 32),
    ]);

    $response = FixtureTools::call('app_info');
    $body = (string) $response->getContent();
    $direct = (string) json_encode(appInfoExecute());
    $needles = [...array_values($planted), $keyName, 'planted-host-9c1e', 'planted-webhook-1d7a', 'PLANTED'];

    expect(FixtureTools::envelope($response)['status'])->toBe('ok');

    foreach ($needles as $needle) {
        expect(str_contains($body, $needle))->toBeFalse("[{$needle}] is in the HTTP body")
            ->and(str_contains($direct, $needle))->toBeFalse("[{$needle}] is in the output of execute()");
    }
});

it('warns about APP_DEBUG only in a strict environment', function (string $environment, bool $debug, array $warnings) {
    appInfoServe(['app.debug' => $debug], $environment);

    $data = appInfoData();

    expect($data['flags'])->toMatchArray(['debug' => $debug])
        ->and($data['app'])->toMatchArray(['environment' => $environment])
        ->and($data['warnings'])->toBe($warnings);
})->with([
    'debug on, production' => ['production', true, [AppInfo::DEBUG_WARNING]],
    'debug on, an unknown name is strict' => ['prod-eu', true, [AppInfo::DEBUG_WARNING]],
    'debug on, local' => ['local', true, []],
    'debug off, production' => ['production', false, []],
]);

it('reads app.debug with the truthiness of the error page', function (mixed $value, bool $expected) {
    appInfoServe(['app.debug' => $value]);

    expect(appInfoData()['flags'])->toMatchArray(['debug' => $expected]);
})->with([
    'the string "1"' => ['1', true],
    'the string "0"' => ['0', false],
    'null' => [null, false],
]);

// execute() directly: the request itself uses the session and cache drivers, so a broken one stops it first.
it('says unknown for a driver value that is not a plain name', function (array $config, string $field) {
    appInfoServe();
    config()->set($config);

    $drivers = appInfoExecute()['drivers'];

    expect($drivers)->toBeArray()
        ->and(is_array($drivers) ? $drivers[$field] : null)->toBe('unknown');
})->with([
    'a class name' => [['cache.default' => 'main', 'cache.stores.main' => ['driver' => 'Illuminate\\Cache\\RedisStore']], 'cache'],
    'a URL' => [['queue.default' => 'jobs', 'queue.connections.jobs' => ['driver' => 'https://queue.example.test/jobs']], 'queue'],
    'a missing store' => [['cache.default' => 'nowhere'], 'cache'],
    'a default that is not a string' => [['queue.default' => ['redis']], 'queue'],
    'a mailer with no transport' => [['mail.default' => 'outgoing', 'mail.mailers.outgoing' => ['host' => 'smtp.example.test']], 'mail'],
    'a trailing newline' => [['session.driver' => "redis\n"], 'session'],
    'a session driver that is not a string' => [['session.driver' => ['file']], 'session'],
    'a log channel with a path' => [['logging.default' => 'storage/logs/laravel.log'], 'log_channel'],
    'a name longer than 32 characters' => [['session.driver' => str_repeat('a', 33)], 'session'],
    'a default name with a dot' => [['cache.default' => 'main.inner', 'cache.stores.main' => ['inner' => ['driver' => 'redis']]], 'cache'],
]);

it('accepts a 32 character driver name with dashes and underscores', function () {
    appInfoServe();
    config(['session.driver' => 'My_Custom-driver'.str_repeat('x', 16)]);

    expect(appInfoExecute()['drivers'])->toMatchArray(['session' => 'My_Custom-driver'.str_repeat('x', 16)]);
});

it('lists every spec 6.1 package with its installed version, or null', function () {
    appInfoServe();

    $packages = appInfoData()['packages'];

    expect($packages)->toBe(array_combine(AppInfo::PACKAGES, array_map(appInfoPackageVersion(...), AppInfo::PACKAGES)))
        ->and(is_array($packages) ? $packages['laravel/sanctum'] : null)->toBe(InstalledVersions::getPrettyVersion('laravel/sanctum'))
        ->and(is_array($packages) ? $packages['laravel/horizon'] : 'installed')->toBeNull();
});

it('says unknown for a replaced or provided package, never null', function () {
    appInfoServe();

    // Composer's data for the root package, with horizon only replaced: installed, no pretty version.
    InstalledVersions::reload([
        'root' => ['name' => 'fixture/root', 'pretty_version' => '1.0.0', 'version' => '1.0.0.0', 'reference' => null, 'type' => 'project', 'install_path' => __DIR__, 'aliases' => [], 'dev' => true],
        'versions' => ['laravel/horizon' => ['dev_requirement' => false, 'replaced' => ['*']]],
    ]);

    try {
        $packages = appInfoExecute()['packages'];
    } finally {
        InstalledVersions::reload(null); // @phpstan-ignore argument.type (null makes Composer read vendor/ again)
    }

    expect($packages)->toMatchArray(['laravel/horizon' => 'unknown', 'laravel/sanctum' => InstalledVersions::getPrettyVersion('laravel/sanctum')])
        ->and(InstalledVersions::isInstalled('laravel/horizon'))->toBeFalse();
});

it('lists only the active plugins', function () {
    appInfoServe(['runtime-mcp.plugins' => ['core' => true, 'models' => false, 'database' => false, 'queue' => true, 'logs' => false]]);

    expect(appInfoData()['plugins'])->toBe([
        ['name' => 'core', 'description' => (new CorePlugin)->description()],
        ['name' => 'queue', 'description' => app(QueuePlugin::class)->description()],
    ]);
});

it('keeps the tool contract', function () {
    appInfoServe();

    assertToolContract(app(AppInfo::class), new CorePlugin);

    expect(app(AppInfo::class)->name())->toBe('app_info')
        ->and(app(AppInfo::class)->ability())->toBe('runtime-mcp.app_info');
});

it('writes one audit entry with no response data', function () {
    appInfoServe(APP_INFO_PINNED);

    FixtureTools::call('app_info');
    $entries = AuditLog::entries();
    $line = implode("\n", AuditLog::lines());

    expect($entries)->toHaveCount(1)
        ->and($entries[0])->toMatchArray(['tool' => 'app_info', 'params' => [], 'decision' => 'allowed', 'outcome' => 'success'])
        ->and(str_contains($line, '"packages"'))->toBeFalse()
        ->and(str_contains($line, 'Shop'))->toBeFalse()
        ->and(str_contains($line, PHP_VERSION))->toBeFalse();
});

it('refuses an argument as invalid input, and audits it', function () {
    appInfoServe();

    $envelope = FixtureTools::envelope(FixtureTools::call('app_info', ['verbose' => true]));

    expect($envelope['status'])->toBe('unavailable')
        ->and($envelope['note'])->toBeString()->toStartWith('Invalid input:')
        ->and(AuditLog::entries())->toHaveCount(1)
        ->and(AuditLog::entries()[0])->toMatchArray(['tool' => 'app_info', 'outcome' => 'error']);
});

it('is hidden and denied while the app defines no Gate', function () {
    appInfoServe(gate: false);

    $names = currentTest()->mcpPost('tools/list')->assertOk()->json('result.tools.*.name');
    $response = FixtureTools::call('app_info');

    expect($names)->toBe([])
        ->and(FixtureTools::envelope($response))->toMatchArray([
            'status' => 'unavailable',
            'note' => 'Access denied: the app defines no Gate `runtime-mcp.app_info`, so this tool is off (default deny).',
            'data' => null,
        ])
        ->and(str_contains((string) $response->getContent(), '"packages"'))->toBeFalse()
        ->and(AuditLog::entries())->toHaveCount(1)
        ->and(AuditLog::entries()[0])->toMatchArray(['tool' => 'app_info', 'decision' => 'denied']);
});

it('reports the cached config and routes', function () {
    appInfoServe();
    app()->instance('config_loaded_from_cache', true);
    app()->instance('routes.cached', true);

    expect(appInfoData()['flags'])->toMatchArray(['config_cached' => true, 'routes_cached' => true]);
});

it('reports maintenance mode with the file driver', function () {
    appInfoServe(['app.maintenance.driver' => 'file']);
    app()->maintenanceMode()->activate(['secret' => 'PLANTED-BYPASS-4e8d']);

    try {
        // The HTTP request itself would get 503 from the app's maintenance middleware, so call execute().
        $data = appInfoExecute();
    } finally {
        app()->maintenanceMode()->deactivate();
    }

    expect($data['flags'])->toMatchArray(['maintenance_mode' => true])
        ->and(str_contains((string) json_encode($data), 'PLANTED-BYPASS'))->toBeFalse()
        ->and(app()->isDownForMaintenance())->toBeFalse();
});

it('gives null and one warning with the class only when the maintenance mode cannot be read', function () {
    appInfoServe([], 'production');
    Exceptions::fake();
    app()->instance(MaintenanceMode::class, new class implements MaintenanceMode
    {
        /** @param  array<string, mixed>  $payload */
        public function activate(array $payload): void {}

        public function deactivate(): void {}

        public function active(): bool
        {
            throw new RuntimeException('Connection refused [tcp://planted-cache-host-5f2a:6379]');
        }

        /** @return array<string, mixed> */
        public function data(): array
        {
            return [];
        }
    });

    $data = appInfoExecute();

    expect($data['flags'])->toMatchArray(['maintenance_mode' => null, 'debug' => false])
        ->and($data['warnings'])->toBe([sprintf(AppInfo::MAINTENANCE_WARNING, RuntimeException::class)])
        ->and($data['versions'])->toMatchArray(['php' => PHP_VERSION])
        ->and(str_contains((string) json_encode($data), 'planted-cache-host'))->toBeFalse();

    Exceptions::assertReported(RuntimeException::class);
    Exceptions::assertReportedCount(1);
});
