<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum as SanctumConfig;
use SinaSaeedi\RuntimeMcp\Console\DoctorCommand;
use SinaSaeedi\RuntimeMcp\Doctor\CheckResult;
use SinaSaeedi\RuntimeMcp\Doctor\Checks\Sanctum;
use SinaSaeedi\RuntimeMcp\Doctor\Doctor;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Auth\CustomToken;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Auth\QualifiedToken;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Auth\TokenUser;
use SinaSaeedi\RuntimeMcp\Tests\Support\Tokens;

/*
 * Spec 5.3, 7.3 (01-35): the Sanctum doctor check. The endpoint stays off (enabled = false), so boot
 * validates nothing and each test sets the config it needs. Helpers start with "sanctumCheck".
 */

afterEach(fn () => SanctumConfig::usePersonalAccessTokenModel(PersonalAccessToken::class));

/** @return list<array{string, string, ?string}> status, message, fix */
function sanctumCheckRun(?Sanctum $check = null): array
{
    return array_map(
        fn (CheckResult $result): array => [$result->status->value, $result->message, $result->fix],
        ($check ?? app(Sanctum::class))->run(),
    );
}

/** @param  array<string, mixed>  $config */
function sanctumCheckBoot(array $config = []): void
{
    currentTest()->bootWith([...Tokens::config(), 'runtime-mcp.enabled' => false, ...$config]);
}

it('is the Sanctum check, where the Auth driver check was', function () {
    expect(Doctor::CHECKS[2])->toBe(Sanctum::class)
        ->and(app(Sanctum::class)->title())->toBe('Sanctum');
});

it('passes with Not used for any driver but sanctum', function (mixed $driver) {
    sanctumCheckBoot(['runtime-mcp.auth.driver' => $driver]);

    expect(sanctumCheckRun())->toBe([['pass', 'Not used: auth.driver is not `sanctum`.', null]]);
})->with(['api_key', 'passport', null]);

it('passes for a user model with HasApiTokens and a denied token table', function () {
    sanctumCheckBoot();

    expect(sanctumCheckRun())->toBe([
        ['pass', '`'.TokenUser::class.'` can own tokens.', null],
        ['pass', '`personal_access_tokens` is in denied_tables.', null],
    ]);
});

it('fails for a user model without HasApiTokens, and prints only a class-like value', function (string $model, string $shown) {
    sanctumCheckBoot(['auth.providers.users.model' => $model]);

    expect(sanctumCheckRun()[0])->toBe([
        'fail',
        "{$shown} cannot own tokens, so every token is rejected.",
        'Add the Laravel\Sanctum\HasApiTokens trait to the user model.',
    ]);
})->with([
    'Testbench user' => [User::class, '`'.User::class.'`'],
    'a class that does not exist' => ['App\Models\Gone', '`App\Models\Gone`'],
    'not a class name' => ['postgres://user:secret@db/app', 'The user model'],
]);

it('fails when the user provider of the default guard has no model', function (array $config) {
    /** @var array<string, mixed> $config */
    sanctumCheckBoot($config);

    expect(sanctumCheckRun()[0])->toBe([
        'fail',
        'The user provider of the default guard has no model, so no token can have an owner, and every token is rejected.',
        'Use an eloquent user provider for the default guard in config/auth.php.',
    ]);
})->with([
    'a database provider' => [['auth.providers.users.driver' => 'database']],
    'no model' => [['auth.providers.users.model' => null]],
    'an unknown guard' => [['auth.defaults.guard' => 'nope', 'auth.defaults.provider' => 'nope']],
]);

it('finds the provider of the default guard, or auth.defaults.provider', function () {
    sanctumCheckBoot([
        'auth.guards.web.provider' => 'admins',
        'auth.providers.admins' => ['driver' => 'eloquent', 'model' => TokenUser::class],
        'auth.providers.users.model' => User::class,
    ]);
    expect(sanctumCheckRun()[0][0])->toBe('pass');

    config(['auth.guards.web.provider' => null, 'auth.defaults.provider' => 'admins']);
    expect(sanctumCheckRun()[0][0])->toBe('pass');
});

it('warns when the token table is not denied, and names the table of a custom token model', function () {
    sanctumCheckBoot(['runtime-mcp.database.denied_tables' => ['sessions']]);

    expect(sanctumCheckRun()[1])->toBe([
        'warn',
        '`personal_access_tokens` is not in denied_tables, so the read-only role may read token rows.',
        'Put personal_access_tokens back in runtime-mcp.database.denied_tables.',
    ]);

    SanctumConfig::usePersonalAccessTokenModel(CustomToken::class);
    expect(sanctumCheckRun()[1][1])->toBe('`custom_tokens` is not in denied_tables, so the read-only role may read token rows.');

    config(['runtime-mcp.database.denied_tables' => ['custom_tokens']]);
    expect(sanctumCheckRun()[1])->toBe(['pass', '`custom_tokens` is in denied_tables.', null]);
});

it('matches the token table as denied_tables does: any case', function () {
    sanctumCheckBoot(['runtime-mcp.database.denied_tables' => ['Personal_Access_Tokens']]);

    expect(sanctumCheckRun()[1][0])->toBe('pass');
});

it('warns without a check when denied_tables is not an array', function () {
    sanctumCheckBoot(['runtime-mcp.database.denied_tables' => 'personal_access_tokens']);

    expect(sanctumCheckRun()[1])->toBe([
        'warn',
        'Not checked: database.denied_tables is not an array (see Mask lists).',
        'Set runtime-mcp.database.denied_tables to a list of table names.',
    ]);
});

it('only warns when laravel/sanctum is missing: the boot rule fails for it', function () {
    sanctumCheckBoot();

    expect(sanctumCheckRun(new Sanctum(app(Repository::class), 'acme/not-installed')))->toBe([[
        'warn',
        'Not checked: laravel/sanctum is not installed (see Boot rules).',
        'Run composer require laravel/sanctum.',
    ]]);
});

it('fails doctor for the sanctum driver with a user model that cannot own tokens', function () {
    sanctumCheckBoot(['auth.providers.users.model' => User::class, 'runtime-mcp.plugins.database' => false, 'runtime-mcp.plugins.models' => false]);

    expect(Artisan::call(DoctorCommand::NAME))->toBe(1)
        ->and(Artisan::output())->toContain('cannot own tokens');
});

// 01-35 re-review: denied_tables may name the table with its schema (DeniedTables), so the check gives
// the token table the default schema of its connection, read from the config with no query.
it('matches a denied_tables entry with the schema of the token connection', function (array $connection, string $entry, string $status) {
    sanctumCheckBoot(['runtime-mcp.database.denied_tables' => [$entry]]);
    config(['database.connections.sanctum_check' => $connection, 'database.default' => 'sanctum_check']);

    expect(sanctumCheckRun()[1][0])->toBe($status);
})->with([
    'PostgreSQL, the public schema' => [['driver' => 'pgsql', 'database' => 'app', 'search_path' => 'public'], 'public.personal_access_tokens', 'pass'],
    'PostgreSQL, another schema first' => [['driver' => 'pgsql', 'database' => 'app', 'search_path' => 'app,public'], 'public.personal_access_tokens', 'warn'],
    'MySQL, the database name' => [['driver' => 'mysql', 'database' => 'shop'], 'shop.personal_access_tokens', 'pass'],
    'MySQL, another database' => [['driver' => 'mysql', 'database' => 'shop'], 'other.personal_access_tokens', 'warn'],
    'SQLite, main' => [['driver' => 'sqlite', 'database' => ':memory:'], 'main.personal_access_tokens', 'pass'],
]);

it('splits the schema-qualified table of a custom token model', function (string $entry, string $status) {
    sanctumCheckBoot(['runtime-mcp.database.denied_tables' => [$entry]]);
    SanctumConfig::usePersonalAccessTokenModel(QualifiedToken::class);

    expect(sanctumCheckRun()[1])->toBe($status === 'pass'
        ? ['pass', '`auth.tokens` is in denied_tables.', null]
        : ['warn', '`auth.tokens` is not in denied_tables, so the read-only role may read token rows.', 'Put auth.tokens back in runtime-mcp.database.denied_tables.']);
})->with([
    'the exact entry' => ['auth.tokens', 'pass'],
    'the table in any schema' => ['tokens', 'pass'],
    'another schema' => ['public.tokens', 'warn'],
]);
