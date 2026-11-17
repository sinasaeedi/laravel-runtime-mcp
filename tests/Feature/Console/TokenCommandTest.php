<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use SinaSaeedi\RuntimeMcp\Console\TokenCommand;
use SinaSaeedi\RuntimeMcp\Security\Auth\ClientSetup;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Auth\DefaultConnectionToken;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Auth\GenericTokenOwner;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Auth\LookalikeToken;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Auth\OtherConnectionToken;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Auth\OtherConnectionTokenUser;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Auth\TokenUser;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Auth\WrappedTokenUser;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\CommandTrail;
use SinaSaeedi\RuntimeMcp\Tests\Support\Tokens;

/*
 * Spec 5.3, 7.2a, rule 21 (01-27): php artisan runtime-mcp:token <email>. The Sanctum harness of 01-35: Tokens::config()
 * (endpoint on, driver sanctum, TokenUser as the user model), Tokens::tables() and Tokens::user(). The time is fixed, so
 * the expiry is exact. Helpers start with "tokenCommand".
 */

const TOKEN_COMMAND_NOW = '2026-11-17 10:00:00';

/**
 * @param  array<string, mixed>  $config
 */
function tokenCommandBoot(array $config = [], ?string $environment = null): TokenUser
{
    currentTest()->bootWith(Tokens::config($config), $environment);

    return Tokens::user();
}

/**
 * @param  array<string, mixed>  $options
 * @return array{int, string}
 */
function tokenCommandRun(string $email = Tokens::EMAIL, array $options = []): array
{
    $code = Artisan::call(TokenCommand::NAME, ['email' => $email, ...$options]);

    return [$code, Artisan::output()];
}

/** The one Sanctum token "<id>|<secret>" in the output. */
function tokenCommandToken(string $output): string
{
    expect(preg_match_all('/(?<![\w|])\d+\|[A-Za-z0-9]{40}[0-9a-f]{8}(?![\w|])/', $output, $matches))->toBeGreaterThan(0);
    $tokens = array_values(array_unique($matches[0]));
    expect($tokens)->toHaveCount(1);

    return $tokens[0];
}

/** The stored expiry as "Y-m-d H:i:s", or null. */
function tokenCommandExpiry(?PersonalAccessToken $row): ?string
{
    $expiresAt = $row?->getAttribute('expires_at');

    return $expiresAt instanceof DateTimeInterface ? $expiresAt->format('Y-m-d H:i:s') : null;
}

function tokenCommandRows(): int
{
    return DB::table('personal_access_tokens')->count();
}

beforeEach(function () {
    AuditLog::clear();
    Carbon::setTestNow(TOKEN_COMMAND_NOW);
});

afterEach(function () {
    Carbon::setTestNow();
    AuditLog::clear();
    // Sanctum keeps it in a static property (01-35): never let a test leave it set.
    Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
});

it('TC1 creates one token with exactly the runtime-mcp ability and a 90-day expiry, and prints it once with the client command', function () {
    $user = tokenCommandBoot(['app.url' => 'https://staging.example.com'], 'staging');

    [$code, $output] = tokenCommandRun();
    $token = tokenCommandToken($output);
    $row = PersonalAccessToken::findToken($token);

    expect($code)->toBe(0)
        ->and(tokenCommandRows())->toBe(1)
        ->and($row)->toBeInstanceOf(PersonalAccessToken::class)
        ->and($row?->getAttribute('abilities'))->toBe(['runtime-mcp'])
        ->and($row?->getAttribute('name'))->toBe('runtime-mcp')
        ->and($row?->getAttribute('tokenable_id'))->toBe($user->getKey())
        ->and($row?->getAttribute('tokenable_type'))->toBe(TokenUser::class)
        ->and(tokenCommandExpiry($row))->toBe('2027-02-15 10:00:00')
        ->and($output)->toBe(implode("\n", [
            '',
            ' Token for "sina@example.com" on Runtime Test (staging) — shown once. Store it in your MCP client or password manager.',
            '',
            "   {$token}",
            '',
            ' 1. Add the server to Claude Code:',
            '',
            "   claude mcp add --transport http runtime-test-staging https://staging.example.com/mcp --header \"Authorization: Bearer {$token}\"",
            '',
            ' 2. Define a Gate for each tool this user may use, for example in AppServiceProvider::boot():',
            '',
            "   Gate::define('runtime-mcp.app_info', fn (User \$user) => true);",
            '',
            ' 3. Optional: check the endpoint from your own machine with the MCP Inspector:',
            '',
            '   npx @modelcontextprotocol/inspector',
            "   → Transport: Streamable HTTP · URL: https://staging.example.com/mcp · Header: Authorization: Bearer {$token}",
            '',
            " The token is stored in this server's database and expires on 2027-02-15 (90 days).",
            " Revoke it with: \$user->tokens()->where('name', 'runtime-mcp')->delete()",
            '',
            '',
        ]));
});

it('TC2 prints a token that authenticates over HTTP (01-35)', function () {
    tokenCommandBoot();

    $token = tokenCommandToken(tokenCommandRun()[1]);

    currentTest()->mcpPost('tools/list', key: $token)->assertOk();
    currentTest()->mcpPost('tools/list', key: $token.'x')->assertUnauthorized();
});

it('TC3 refuses with exit 1 and no row when the driver is not sanctum', function (mixed $driver, string $message) {
    tokenCommandBoot();
    config(['runtime-mcp.auth.driver' => $driver]); // after boot: the boot rule would throw in testing

    [$code, $output] = tokenCommandRun();

    expect($code)->toBe(1)->and($output)->toContain($message)->and(tokenCommandRows())->toBe(0);
})->with([
    'api_key' => ['api_key', 'The auth driver is `api_key`. Use php artisan runtime-mcp:key <name>, or set the driver to `sanctum` first.'],
    'another name' => ['passport', TokenCommand::OTHER_DRIVER],
    'not a string' => [null, TokenCommand::OTHER_DRIVER],
]);

it('TC4 refuses with exit 1 when laravel/sanctum is not installed', function () {
    tokenCommandBoot();
    app()->bind(TokenCommand::class, fn (): TokenCommand => new TokenCommand('Acme\Missing\Sanctum'));

    [$code, $output] = tokenCommandRun();

    expect($code)->toBe(1)->and($output)->toContain(TokenCommand::NOT_INSTALLED)->and(tokenCommandRows())->toBe(0);
});

it('TC5 refuses an unknown email, and a user of another table with that email', function () {
    tokenCommandBoot();
    Tokens::otherOwner('other@example.com');

    foreach (['nobody@example.com', 'other@example.com', ''] as $email) {
        [$code, $output] = tokenCommandRun($email);

        expect($code)->toBe(1)->and($output)->toContain(TokenCommand::NO_USER);
    }

    expect(tokenCommandRows())->toBe(0);
});

it('TC6 refuses a user model that cannot own tokens, and names it only when it has the shape of a class', function (string $model, string $shown) {
    tokenCommandBoot(['auth.providers.users.model' => $model]);

    [$code, $output] = tokenCommandRun();

    expect($code)->toBe(1)
        ->and($output)->toContain("{$shown} cannot own a token: it must be an Eloquent model that is Authenticatable and uses Laravel\\Sanctum\\HasApiTokens.")
        ->and(tokenCommandRows())->toBe(0);
})->with([
    'no trait' => [User::class, '`Illuminate\Foundation\Auth\User`'],
    'no class' => ['App\Models\Missing', '`App\Models\Missing`'],
    'not a model' => [GenericTokenOwner::class, '`SinaSaeedi\RuntimeMcp\Tests\Fixtures\Auth\GenericTokenOwner`'],
    'not Authenticatable' => [ArrayObject::class, '`ArrayObject`'],
    'not a class name' => ['not a class!', 'The user model'],
]);

it('TC7 refuses with exit 1 when the default guard has no eloquent provider', function () {
    tokenCommandBoot(['auth.providers.users.driver' => 'database', 'auth.providers.users.table' => 'users']);

    [$code, $output] = tokenCommandRun();

    expect($code)->toBe(1)->and($output)->toContain(TokenCommand::NO_MODEL)->and(tokenCommandRows())->toBe(0);
});

it('TC8 makes a token with no expiry for --days=0, and says so', function () {
    tokenCommandBoot();

    [$code, $output] = tokenCommandRun(options: ['--days' => '0']);
    $row = PersonalAccessToken::findToken(tokenCommandToken($output));

    expect($code)->toBe(0)
        ->and(tokenCommandExpiry($row))->toBeNull()
        ->and($output)->toContain(" The token is stored in this server's database.\n")
        ->and($output)->toContain(TokenCommand::NEVER_EXPIRES);
});

it('TC9 takes --days from 1 to 3650', function (string $days, string $date) {
    tokenCommandBoot();

    [$code, $output] = tokenCommandRun(options: ['--days' => $days]);
    $row = PersonalAccessToken::findToken(tokenCommandToken($output));

    expect($code)->toBe(0)
        ->and(tokenCommandExpiry($row))->toBe($date)
        ->and($output)->toContain('expires on '.substr($date, 0, 10).' ('.(int) $days.' days).')
        ->and(str_contains($output, TokenCommand::NEVER_EXPIRES))->toBeFalse();
})->with([
    'one day' => ['1', '2026-11-18 10:00:00'],
    'the most' => ['3650', '2036-11-14 10:00:00'],
    'a leading zero' => ['07', '2026-11-24 10:00:00'],
]);

it('TC10 refuses another --days value with exit 1 and no row', function (string $days) {
    tokenCommandBoot();

    [$code, $output] = tokenCommandRun(options: ['--days' => $days]);

    expect($code)->toBe(1)->and($output)->toContain(TokenCommand::BAD_DAYS)->and(tokenCommandRows())->toBe(0);
})->with(['3651', '-1', 'abc', '', '1.5', ' 9', '99999999999999999999']);

it('TC11 warns that sanctum.expiration also applies, also for --days=0', function (mixed $minutes, string $days) {
    tokenCommandBoot(['sanctum.expiration' => $minutes]);

    [$code, $output] = tokenCommandRun(options: ['--days' => $days]);

    expect($code)->toBe(0)
        ->and($output)->toContain("Sanctum's sanctum.expiration also applies: this token stops working 60 minutes after it was created.")
        ->and(str_contains($output, TokenCommand::NEVER_EXPIRES))->toBeFalse()
        ->and(tokenCommandRows())->toBe(1);
})->with([
    'an integer' => [60, '90'],
    'digits' => ['60', '90'],
    'no expiry of its own' => [60, '0'],
]);

it('TC12 gives no sanctum.expiration warning when it is off', function (mixed $minutes) {
    tokenCommandBoot(['sanctum.expiration' => $minutes]);

    [$code, $output] = tokenCommandRun();

    expect($code)->toBe(0)->and(str_contains($output, 'sanctum.expiration'))->toBeFalse();
})->with([null, 0, '0', '']);

it('TC13 refuses a sanctum.expiration that rejects every token, with exit 1 and no row', function (mixed $minutes) {
    tokenCommandBoot(['sanctum.expiration' => $minutes]);

    [$code, $output] = tokenCommandRun();

    expect($code)->toBe(1)->and($output)->toContain(TokenCommand::BAD_EXPIRATION)->and(tokenCommandRows())->toBe(0);
})->with(['soon', -5, 1.5, '1.5', true]);

it('TC14 takes --name as the token name, and refuses an empty or a long one', function () {
    tokenCommandBoot();

    [$code, $output] = tokenCommandRun(options: ['--name' => "ci's"]);

    expect($code)->toBe(0)
        ->and(PersonalAccessToken::findToken(tokenCommandToken($output))?->getAttribute('name'))->toBe("ci's")
        ->and($output)->toContain(" Revoke it with: \$user->tokens()->where('name', 'ci\\'s')->delete()");

    foreach (['', '   ', str_repeat('n', 256)] as $name) {
        [$code, $output] = tokenCommandRun(options: ['--name' => $name]);

        expect($code)->toBe(1)->and($output)->toContain(TokenCommand::BAD_NAME);
    }

    expect(tokenCommandRows())->toBe(1);
});

it('TC15 names only the exception class when the lookup or the save fails', function () {
    tokenCommandBoot();
    Schema::drop('personal_access_tokens');

    [$code, $output] = tokenCommandRun();

    expect($code)->toBe(1)
        ->and($output)->toContain('The token could not be saved (Illuminate\Database\QueryException). Check that the personal_access_tokens table exists.')
        ->and(str_contains($output, 'insert into'))->toBeFalse();

    Schema::drop('users');

    [$code, $output] = tokenCommandRun();

    expect($code)->toBe(1)
        ->and($output)->toContain('The user lookup failed (Illuminate\Database\QueryException).')
        ->and(str_contains($output, Tokens::EMAIL))->toBeFalse();
});

it('TC16 single-quotes the header when sanctum.token_prefix holds a shell character', function () {
    tokenCommandBoot(['sanctum.token_prefix' => 'a$b_']);

    [$code, $output] = tokenCommandRun();
    expect(preg_match('/^   (\d+\|a\$b_\S+)$/m', $output, $match))->toBe(1);
    $token = $match[1] ?? '';

    expect($code)->toBe(0)
        ->and($output)->toContain(" --header 'Authorization: Bearer {$token}'\n");
});

it('TC17 writes only the token row: no file, no log line, and no event holds the token', function () {
    tokenCommandBoot();
    $trail = CommandTrail::start();

    [$code, $output] = tokenCommandRun();
    $token = tokenCommandToken($output);

    expect($code)->toBe(0)
        ->and($trail->texts)->toContain('Illuminate\Console\Events\CommandFinished')
        ->and($trail->eventsHolding($token))->toBe([])
        ->and($trail->eventsHolding(explode('|', $token, 2)[1]))->toBe([])
        ->and($trail->logged)->toBe([])
        ->and($trail->changedFiles())->toBe([])
        ->and(AuditLog::files())->toBe([])
        ->and(tokenCommandRows())->toBe(1);
});

it('TC18 warns about the client setup, as runtime-mcp:key does', function () {
    tokenCommandBoot(['runtime-mcp.enabled' => false, 'app.url' => null]);

    [$code, $output] = tokenCommandRun();

    expect($code)->toBe(0)
        ->and($output)->toContain(ClientSetup::APP_URL_WARNING)
        ->and($output)->toContain(ClientSetup::ENDPOINT_WARNING)
        ->and($output)->toContain(" 'https://<your-host>/mcp' --header ");
});

it('TC19 prints a style tag in the email as text', function () {
    tokenCommandBoot();
    Tokens::user('<fg=red>ops</>@example.com');

    [$code, $output] = tokenCommandRun('<fg=red>ops</>@example.com');

    expect($code)->toBe(0)->and($output)->toContain(' Token for "<fg=red>ops</>@example.com" on Runtime Test (testing) — shown once.');
});

/**
 * 01-27 triage: the user model and the token model on two in-memory SQLite connections, each with the users and the
 * token tables, and the user on the user model's connection.
 */
function tokenCommandBootConnections(string $userModel, string $tokenModel): void
{
    if (! is_a($userModel, User::class, true) || ! is_a($tokenModel, PersonalAccessToken::class, true)) {
        throw new LogicException('A user model and a Sanctum token model are needed.');
    }

    $other = OtherConnectionTokenUser::CONNECTION;
    currentTest()->bootWith(Tokens::config([
        'auth.providers.users.model' => $userModel,
        "database.connections.{$other}" => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
    ]));
    Sanctum::usePersonalAccessTokenModel($tokenModel);
    Tokens::tables();
    Tokens::tables($other);

    $user = new $userModel;
    $user->forceFill(['email' => Tokens::EMAIL])->save();
}

/** The token rows on both connections. */
function tokenCommandRowsEverywhere(): int
{
    return tokenCommandRows() + DB::connection(OtherConnectionTokenUser::CONNECTION)->table('personal_access_tokens')->count();
}

it('TC20 refuses, with no row, a token that the driver would read or whose owner it would load on another connection', function (string $userModel, string $tokenModel) {
    tokenCommandBootConnections($userModel, $tokenModel);

    [$code, $output] = tokenCommandRun();

    expect($code)->toBe(1)
        ->and($output)->toContain(TokenCommand::CONNECTIONS)
        ->and(tokenCommandRowsEverywhere())->toBe(0);
})->with([
    // createToken() writes on the user's connection; the driver reads the default one.
    'the user model on another connection, the token model on none' => [OtherConnectionTokenUser::class, PersonalAccessToken::class],
    // The driver loads the owner on the token's connection, not the user model's (default) one.
    'the token model on another connection, the user model on none' => [TokenUser::class, OtherConnectionToken::class],
]);

it('TC21 makes a token that authenticates when both models name their connection', function (string $userModel, string $tokenModel) {
    tokenCommandBootConnections($userModel, $tokenModel);

    [$code, $output] = tokenCommandRun();
    $token = tokenCommandToken($output);

    expect($code)->toBe(0)->and(tokenCommandRowsEverywhere())->toBe(1);
    currentTest()->mcpPost('tools/list', key: $token)->assertOk();
})->with([
    'the same connection' => [OtherConnectionTokenUser::class, OtherConnectionToken::class],
    'two connections' => [OtherConnectionTokenUser::class, DefaultConnectionToken::class],
]);

it('TC22 refuses a token model that is not a Sanctum PersonalAccessToken, with no row', function () {
    tokenCommandBoot();
    // Sanctum types the setter class-string<PersonalAccessToken>, but it checks nothing: an app can set any class.
    (new ReflectionProperty(Sanctum::class, 'personalAccessTokenModel'))->setValue(null, LookalikeToken::class);

    [$code, $output] = tokenCommandRun();

    expect($code)->toBe(1)->and($output)->toContain(TokenCommand::TOKEN_MODEL)->and(tokenCommandRows())->toBe(0);
});

it('TC23 says that createToken() returned no NewAccessToken, not that no user has the email', function () {
    tokenCommandBoot(['auth.providers.users.model' => WrappedTokenUser::class]);

    [$code, $output] = tokenCommandRun();

    expect($code)->toBe(1)
        ->and($output)->toContain(TokenCommand::NOT_NEW_TOKEN)
        ->and(str_contains($output, TokenCommand::NO_USER))->toBeFalse();
});
