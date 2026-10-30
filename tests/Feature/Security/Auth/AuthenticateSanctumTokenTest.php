<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\SanctumServiceProvider;
use SinaSaeedi\RuntimeMcp\Audit\Events\AuthFailed;
use SinaSaeedi\RuntimeMcp\Audit\Reason;
use SinaSaeedi\RuntimeMcp\Mcp\McpServer;
use SinaSaeedi\RuntimeMcp\Security\Auth\AuthenticateSanctumToken;
use SinaSaeedi\RuntimeMcp\Security\Auth\AuthMethod;
use SinaSaeedi\RuntimeMcp\Security\Auth\CurrentPrincipal;
use SinaSaeedi\RuntimeMcp\Security\Auth\Unauthenticated;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Answer401;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Auth\BaseTokenUser;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Auth\EncryptedAbilitiesToken;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Auth\LookalikeToken;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Auth\OtherConnectionTokenUser;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Auth\OtherTableTokenUser;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Auth\SameTableTokenUser;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Auth\StringKeyToken;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Auth\TokenUser;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Auth\UncastToken;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\FixtureTools;
use SinaSaeedi\RuntimeMcp\Tests\Support\ProtocolEra;
use SinaSaeedi\RuntimeMcp\Tests\Support\RecordingLogger;
use SinaSaeedi\RuntimeMcp\Tests\Support\Tokens;
use SinaSaeedi\RuntimeMcp\Tests\TestCase;
use Symfony\Component\HttpFoundation\Response;

/*
 * Spec 5.3, 11.2 "Sanctum", rule 21. Pest test-file functions are global, so each helper name starts
 * with "sanctumToken". A dataset closure is called by Pest, so the rows name a case and
 * sanctumTokenHeader() builds it after the boot (the tables live in the booted app's connection).
 */

beforeEach(fn () => AuditLog::clear());
afterEach(function () {
    AuditLog::clear();
    // Sanctum keeps both in static properties: never let a test leave them set.
    Sanctum::$accessTokenAuthenticationCallback = null;
    Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
});

/**
 * The sanctum driver on, and its tables in the new app.
 *
 * @param  array<string, mixed>  $config
 */
function sanctumTokenBoot(array $config = []): void
{
    currentTest()->bootWith(Tokens::config($config));
    Tokens::tables();
}

/** @return TestResponse<Response> */
function sanctumTokenPost(?string $authorization, ProtocolEra $era = ProtocolEra::Legacy, string $method = 'tools/list'): TestResponse
{
    $headers = $authorization === null ? [] : ['Authorization' => $authorization];

    return currentTest()->mcpPost($method, headers: $headers, era: $era, key: null);
}

/** @param  TestResponse<Response>  $response */
function sanctumTokenAssertRejected(TestResponse $response): void
{
    $response->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.'])
        ->assertHeader('WWW-Authenticate', 'Bearer realm="runtime-mcp", error="invalid_token"');

    expect($response->getContent())->toBe(Unauthenticated::response()->getContent());
}

/** The Authorization header for one rejection case, after the data it needs is in place. */
function sanctumTokenHeader(string $case): ?string
{
    $user = Tokens::user();

    return match ($case) {
        'no header' => null,
        'Basic' => 'Basic dXNlcjpwYXNz',
        'Bearer alone' => 'Bearer',
        'a bare token, no scheme' => Tokens::issue($user),
        'a wrong secret' => 'Bearer '.Tokens::id(Tokens::issue($user)).'|'.str_repeat('x', 40),
        'an unknown token without an id' => 'Bearer '.str_repeat('y', 40),
        'an id that is not a number' => 'Bearer abc|'.Tokens::secret(Tokens::issue($user)),
        'an id of 19 digits' => 'Bearer '.str_repeat('9', 19).'|x',
        'an empty secret' => 'Bearer '.Tokens::id(Tokens::issue($user)).'|',
        'a valid token with a comma tail' => 'Bearer '.Tokens::issue($user).',x',
        'an API key' => 'Bearer '.TestCase::TEST_KEY,
        'the wildcard ability' => 'Bearer '.Tokens::issue($user, ['*']),
        'the wildcard and other abilities' => 'Bearer '.Tokens::issue($user, ['*', 'read']),
        'a longer ability' => 'Bearer '.Tokens::issue($user, ['runtime-mcp:read']),
        'another case of the ability' => 'Bearer '.Tokens::issue($user, ['Runtime-MCP']),
        'no abilities' => sanctumTokenWithRawAbilities($user, null),
        'abilities that are not a list' => sanctumTokenWithRawAbilities($user, '"runtime-mcp"'),
        'abilities that the cast cannot read' => sanctumTokenWithUnreadableAbilities($user),
        'expires_at in the past' => 'Bearer '.Tokens::issue($user, expiresAt: Carbon::now()->subMinute()),
        'expires_at that the cast cannot read' => sanctumTokenWithRawColumn($user, 'expires_at', 'soon'),
        'expires_at without a date cast' => sanctumTokenWithoutDateCast($user),
        'no created_at while sanctum.expiration is set' => sanctumTokenWithoutCreatedAt($user),
        'sanctum.expiration passed' => sanctumTokenCreatedAgo($user, 120, 60),
        'sanctum.expiration passed, as digits' => sanctumTokenCreatedAgo($user, 120, '60'),
        'sanctum.expiration not a number' => sanctumTokenCreatedAgo($user, 0, 'soon'),
        'the owner was deleted' => sanctumTokenOfDeletedUser(),
        'an owner of another model' => 'Bearer '.Tokens::issue(Tokens::otherOwner('client@example.com')),
        'an owner class that no longer exists' => sanctumTokenOfGoneClass($user),
        'a user provider without a model' => sanctumTokenWithDatabaseProvider($user),
        default => throw new LogicException("Unknown case {$case}."),
    };
}

/** A token row whose abilities column holds this raw JSON (or null). */
function sanctumTokenWithRawAbilities(TokenUser $user, ?string $json): string
{
    $token = Tokens::issue($user);
    DB::table('personal_access_tokens')->where('id', Tokens::id($token))->update(['abilities' => $json]);

    return 'Bearer '.$token;
}

/** A plain JSON abilities column, read by a token model that decrypts it: the cast throws. */
function sanctumTokenWithUnreadableAbilities(TokenUser $user): string
{
    $token = Tokens::issue($user);
    Sanctum::usePersonalAccessTokenModel(EncryptedAbilitiesToken::class);

    return 'Bearer '.$token;
}

function sanctumTokenWithRawColumn(TokenUser $user, string $column, string $value): string
{
    $token = Tokens::issue($user);
    DB::table('personal_access_tokens')->where('id', Tokens::id($token))->update([$column => $value]);

    return 'Bearer '.$token;
}

/** A future expires_at, read by a token model that does not cast it: a string, not a date. */
function sanctumTokenWithoutDateCast(TokenUser $user): string
{
    $token = Tokens::issue($user, expiresAt: Carbon::now()->addYear());
    Sanctum::usePersonalAccessTokenModel(UncastToken::class);

    return 'Bearer '.$token;
}

function sanctumTokenWithoutCreatedAt(TokenUser $user): string
{
    $token = Tokens::issue($user);
    DB::table('personal_access_tokens')->where('id', Tokens::id($token))->update(['created_at' => null]);
    config(['sanctum.expiration' => 60]);

    return 'Bearer '.$token;
}

function sanctumTokenCreatedAgo(TokenUser $user, int $minutes, int|string $expiration): string
{
    $token = Tokens::issue($user);
    DB::table('personal_access_tokens')->where('id', Tokens::id($token))->update(['created_at' => Carbon::now()->subMinutes($minutes)]);
    config(['sanctum.expiration' => $expiration]);

    return 'Bearer '.$token;
}

function sanctumTokenOfDeletedUser(): string
{
    $user = Tokens::user('gone@example.com');
    $token = Tokens::issue($user);
    $user->delete();

    return 'Bearer '.$token;
}

function sanctumTokenOfGoneClass(TokenUser $user): string
{
    $token = Tokens::issue($user);
    DB::table('personal_access_tokens')->where('id', Tokens::id($token))->update(['tokenable_type' => 'App\\Models\\Gone']);

    return 'Bearer '.$token;
}

function sanctumTokenWithDatabaseProvider(TokenUser $user): string
{
    config(['auth.providers.users.driver' => 'database', 'auth.providers.users.table' => 'users']);

    return 'Bearer '.Tokens::issue($user);
}

it('rejects with the reason in the audit only', function (string $case, Reason $reason, array $details, ProtocolEra $era) {
    sanctumTokenBoot();
    $authorization = sanctumTokenHeader($case);
    Event::fake([AuthFailed::class]);

    sanctumTokenAssertRejected(sanctumTokenPost($authorization, $era));

    $records = AuditLog::records();
    expect($records)->toHaveCount(1)
        ->and($records[0]['level_name'] ?? null)->toBe('WARNING')
        ->and($records[0]['message'] ?? null)->toBe('runtime-mcp.request_rejected');

    $entry = AuditLog::entries()[0];
    unset($entry['timestamp']);
    expect($entry)->toBe([
        'kind' => 'request_rejected',
        'environment' => 'testing',
        'principal' => null,
        'user_id' => null,
        'auth_method' => 'sanctum',
        'ip' => '127.0.0.1',
        'client' => null,
        'tool' => null,
        'params' => [],
        'decision' => 'denied',
        'reason' => $reason->value,
        'outcome' => null,
        'error_class' => null,
        'duration_ms' => null,
        'row_count' => null,
        'output_bytes' => null,
        'details' => $details,
    ]);

    Event::assertDispatchedTimes(AuthFailed::class, 1);
    expect(app(CurrentPrincipal::class)->get())->toBeNull();
})->with([
    'no header' => ['no header', Reason::MissingCredentials, []],
    'Basic' => ['Basic', Reason::UnsupportedScheme, ['scheme' => 'Basic']],
    'Bearer alone' => ['Bearer alone', Reason::BlankCredentials, ['scheme' => 'Bearer']],
    'a bare token, no scheme' => ['a bare token, no scheme', Reason::UnsupportedScheme, ['scheme' => '[none]']],
    'a wrong secret' => ['a wrong secret', Reason::UnknownToken, ['scheme' => 'Bearer']],
    'an unknown token without an id' => ['an unknown token without an id', Reason::UnknownToken, ['scheme' => 'Bearer']],
    'an id that is not a number' => ['an id that is not a number', Reason::UnknownToken, ['scheme' => 'Bearer']],
    'an id of 19 digits' => ['an id of 19 digits', Reason::UnknownToken, ['scheme' => 'Bearer']],
    'an empty secret' => ['an empty secret', Reason::UnknownToken, ['scheme' => 'Bearer']],
    'a valid token with a comma tail (Request::bearerToken() would cut it)' => ['a valid token with a comma tail', Reason::UnknownToken, ['scheme' => 'Bearer']],
    'an API key with the sanctum driver' => ['an API key', Reason::UnknownToken, ['scheme' => 'Bearer']],
    'the wildcard ability' => ['the wildcard ability', Reason::TokenMissingAbility, ['scheme' => 'Bearer']],
    'the wildcard and other abilities' => ['the wildcard and other abilities', Reason::TokenMissingAbility, ['scheme' => 'Bearer']],
    'a longer ability' => ['a longer ability', Reason::TokenMissingAbility, ['scheme' => 'Bearer']],
    'another case of the ability' => ['another case of the ability', Reason::TokenMissingAbility, ['scheme' => 'Bearer']],
    'no abilities' => ['no abilities', Reason::TokenMissingAbility, ['scheme' => 'Bearer']],
    'abilities that are not a list' => ['abilities that are not a list', Reason::TokenMissingAbility, ['scheme' => 'Bearer']],
    'abilities that the cast cannot read (never a 500)' => ['abilities that the cast cannot read', Reason::TokenMissingAbility, ['scheme' => 'Bearer']],
    'expires_at in the past' => ['expires_at in the past', Reason::TokenExpired, ['scheme' => 'Bearer']],
    'expires_at that the cast cannot read' => ['expires_at that the cast cannot read', Reason::TokenExpired, ['scheme' => 'Bearer']],
    'expires_at without a date cast' => ['expires_at without a date cast', Reason::TokenExpired, ['scheme' => 'Bearer']],
    'no created_at while sanctum.expiration is set' => ['no created_at while sanctum.expiration is set', Reason::TokenExpired, ['scheme' => 'Bearer']],
    'sanctum.expiration passed' => ['sanctum.expiration passed', Reason::TokenExpired, ['scheme' => 'Bearer']],
    'sanctum.expiration passed, as digits' => ['sanctum.expiration passed, as digits', Reason::TokenExpired, ['scheme' => 'Bearer']],
    'sanctum.expiration not a number' => ['sanctum.expiration not a number', Reason::TokenExpired, ['scheme' => 'Bearer']],
    'the owner was deleted' => ['the owner was deleted', Reason::TokenOwnerInvalid, ['scheme' => 'Bearer']],
    'an owner of another model' => ['an owner of another model', Reason::TokenOwnerInvalid, ['scheme' => 'Bearer']],
    'an owner class that no longer exists' => ['an owner class that no longer exists', Reason::TokenOwnerInvalid, ['scheme' => 'Bearer']],
    'a user provider without a model' => ['a user provider without a model', Reason::TokenOwnerInvalid, ['scheme' => 'Bearer']],
])->with([ProtocolEra::Legacy, ProtocolEra::Modern]);

it('lets a valid token reach laravel/mcp, with the owner as the principal', function (ProtocolEra $era) {
    sanctumTokenBoot();
    $user = Tokens::user();
    $token = Tokens::issue($user);

    sanctumTokenPost('Bearer '.$token, $era)->assertOk();

    $principal = app(CurrentPrincipal::class)->get();
    expect($principal?->name)->toBe(Tokens::principalName($user))
        ->and($principal?->method)->toBe(AuthMethod::Sanctum)
        ->and($principal?->user)->toBeInstanceOf(TokenUser::class)
        ->and($principal?->user?->getAuthIdentifier())->toBe($user->getKey())
        ->and($principal?->subject())->toBe($principal?->user)
        ->and(Auth::guard()->hasUser())->toBeFalse()
        ->and(AuditLog::records())->toBe([]);
})->with([ProtocolEra::Legacy, ProtocolEra::Modern]);

it('accepts the token in any case of Bearer, and its secret alone (found by the hash, as Sanctum does)', function (string $form) {
    sanctumTokenBoot();
    $token = Tokens::issue(Tokens::user());
    $authorization = match ($form) {
        'lower case' => 'bearer '.$token,
        'tabs' => "Bearer \t".$token,
        'the secret alone' => 'Bearer '.Tokens::secret($token),
        default => throw new LogicException($form),
    };

    sanctumTokenPost($authorization)->assertOk();
    expect(app(CurrentPrincipal::class)->get()?->method)->toBe(AuthMethod::Sanctum);
})->with(['lower case', 'tabs', 'the secret alone']);

it('accepts more abilities beside runtime-mcp, a future expires_at, and no global expiry', function (mixed $expiration) {
    sanctumTokenBoot();
    config(['sanctum.expiration' => $expiration]);
    $token = Tokens::issue(Tokens::user(), ['read', 'runtime-mcp'], Carbon::now()->addMinute());
    DB::table('personal_access_tokens')->update(['created_at' => Carbon::now()->subYear()]);

    sanctumTokenPost('Bearer '.$token)->assertOk();
})->with(['null' => [null], 'zero' => [0], 'zero as digits' => ['0'], 'empty' => ['']]);

it('accepts a token inside sanctum.expiration', function (int|string $expiration) {
    sanctumTokenBoot();
    config(['sanctum.expiration' => $expiration]);
    $token = Tokens::issue(Tokens::user());
    DB::table('personal_access_tokens')->update(['created_at' => Carbon::now()->subMinutes(59)]);

    sanctumTokenPost('Bearer '.$token)->assertOk();
})->with(['minutes' => [60], 'digits' => ['60']]);

it('sets last_used_at, and keeps the connection unmodified for a sticky read/write setup', function () {
    sanctumTokenBoot();
    $token = Tokens::issue(Tokens::user());
    Carbon::setTestNow('2026-10-20 12:00:00');

    try {
        // The inserts above marked the connection; a new request starts unmarked.
        DB::connection()->forgetRecordModificationState();

        sanctumTokenPost('Bearer '.$token)->assertOk();

        expect(DB::table('personal_access_tokens')->value('last_used_at'))->toBe('2026-10-20 12:00:00')
            ->and(DB::connection()->hasModifiedRecords())->toBeFalse();
    } finally {
        Carbon::setTestNow();
    }
});

it('goes on with one warning when last_used_at cannot be saved', function () {
    sanctumTokenBoot();
    $token = Tokens::issue(Tokens::user());
    $log = RecordingLogger::install();
    PersonalAccessToken::saving(fn () => throw new RuntimeException('the token '.$token));

    sanctumTokenPost('Bearer '.$token)->assertOk();

    expect($log->messages())->toBe(['Runtime MCP: could not update last_used_at of a Sanctum token (RuntimeException).'])
        ->and(app(CurrentPrincipal::class)->get()?->method)->toBe(AuthMethod::Sanctum);
});

it('rejects with token_lookup_failed when the lookup throws, and logs the class only', function () {
    sanctumTokenBoot();
    $token = Tokens::issue(Tokens::user());
    Schema::drop('personal_access_tokens');
    $log = RecordingLogger::install();
    Exceptions::fake();

    sanctumTokenAssertRejected(sanctumTokenPost('Bearer '.Tokens::secret($token)));

    expect(AuditLog::entries()[0]['reason'] ?? null)->toBe(Reason::TokenLookupFailed->value)
        ->and($log->messages())->toBe(['Runtime MCP: the Sanctum token lookup failed (Illuminate\Database\QueryException), so the request was rejected.']);
    Exceptions::assertNothingReported();
});

it('rejects with token_lookup_failed when the token model is not a Sanctum token model', function () {
    sanctumTokenBoot();
    $token = Tokens::issue(Tokens::user());
    // Sanctum types the setter class-string<PersonalAccessToken>, but it checks nothing: an app can set any class.
    (new ReflectionProperty(Sanctum::class, 'personalAccessTokenModel'))->setValue(null, LookalikeToken::class);

    sanctumTokenAssertRejected(sanctumTokenPost('Bearer '.$token));

    expect(AuditLog::entries()[0]['reason'] ?? null)->toBe(Reason::TokenLookupFailed->value);
});

it('never sends a malformed id to the database', function (string $authorization) {
    sanctumTokenBoot();
    $queries = 0;
    Event::listen(QueryExecuted::class, function () use (&$queries): void {
        $queries++;
    });

    sanctumTokenAssertRejected(sanctumTokenPost($authorization));

    expect($queries)->toBe(0)
        ->and(AuditLog::entries()[0]['reason'] ?? null)->toBe(Reason::UnknownToken->value);
})->with([
    'not a number' => ['Bearer abc|secret'],
    'a sign' => ['Bearer -1|secret'],
    '19 digits' => ['Bearer 1234567890123456789|secret'],
    'an empty secret' => ['Bearer 12|'],
]);

it('looks up an id of 18 digits, and any id for a string key, as Sanctum does', function (string $model, string $authorization) {
    sanctumTokenBoot();
    // A dataset row holds the class name as a string; the setter wants a class-string of a token model.
    (new ReflectionProperty(Sanctum::class, 'personalAccessTokenModel'))->setValue(null, $model);
    $queries = 0;
    Event::listen(QueryExecuted::class, function () use (&$queries): void {
        $queries++;
    });

    sanctumTokenAssertRejected(sanctumTokenPost($authorization));

    expect($queries)->toBe(1)
        ->and(AuditLog::entries()[0]['reason'] ?? null)->toBe(Reason::UnknownToken->value);
})->with([
    'an int key, 18 digits' => [PersonalAccessToken::class, 'Bearer 123456789012345678|secret'],
    'a string key' => [StringKeyToken::class, 'Bearer abc|secret'],
]);

it('never runs the app callback Sanctum::authenticateAccessTokensUsing()', function () {
    sanctumTokenBoot();
    $user = Tokens::user();
    $expired = Tokens::issue($user, expiresAt: Carbon::now()->subMinute());
    $valid = Tokens::issue($user);

    Sanctum::authenticateAccessTokensUsing(fn (): bool => true);
    sanctumTokenAssertRejected(sanctumTokenPost('Bearer '.$expired));

    Sanctum::authenticateAccessTokensUsing(fn (): bool => false);
    sanctumTokenPost('Bearer '.$valid)->assertOk();
});

it('never writes the token, its hash or its id form to the audit or the app log', function () {
    sanctumTokenBoot();
    $token = Tokens::issue(Tokens::user(), ['*']);
    $log = RecordingLogger::install();

    sanctumTokenAssertRejected(sanctumTokenPost('Bearer '.$token));

    $written = implode("\n", [...AuditLog::lines(), ...$log->messages()]);
    expect($written)->not->toBe('')
        ->and(str_contains($written, Tokens::secret($token)))->toBeFalse()
        ->and(str_contains($written, hash('sha256', Tokens::secret($token))))->toBeFalse()
        ->and(str_contains($written, Tokens::id($token).'|'))->toBeFalse();
});

it('runs Gates for the owner, typed as the app user model', function () {
    FixtureTools::serve(Tokens::config(), gates: false);
    Tokens::tables();
    $user = Tokens::user();
    $token = Tokens::issue($user);
    Gate::define('runtime-mcp.echo', fn (TokenUser $owner): bool => $owner->is($user));

    $response = currentTest()->mcpPost('tools/call', ['name' => 'echo', 'arguments' => (object) ['text' => 'hi']], key: $token)->assertOk();

    expect(FixtureTools::envelope($response)['status'] ?? null)->toBe('ok');
    $entry = AuditLog::entries()[0] ?? [];
    expect($entry['principal'] ?? null)->toBe(Tokens::principalName($user))
        ->and($entry['auth_method'] ?? null)->toBe('sanctum')
        ->and($entry['user_id'] ?? null)->toBe($user->getKey())
        ->and($entry['decision'] ?? null)->toBe('allowed');
});

it('refuses a signed-in web user without a token, also behind Sanctum stateful middleware', function () {
    sanctumTokenBoot([
        'runtime-mcp.middleware' => [EnsureFrontendRequestsAreStateful::class],
        'sanctum.stateful' => ['app.test'],
    ]);
    app()->register(SanctumServiceProvider::class);
    $user = Tokens::user();
    currentTest()->actingAs($user, 'web');

    // Control: the official way (auth:sanctum) lets this browser request in.
    Route::middleware([EnsureFrontendRequestsAreStateful::class, 'auth:sanctum'])->post('/control', fn (): string => 'in');
    currentTest()->post('/control', [], ['Referer' => 'https://app.test/admin'])->assertOk()->assertSee('in');

    sanctumTokenAssertRejected(sanctumTokenPost(null)->assertUnauthorized());
    $response = currentTest()->mcpPost('tools/list', headers: ['Referer' => 'https://app.test/admin'], key: null);

    sanctumTokenAssertRejected($response);
    expect(array_column(AuditLog::entries(), 'reason'))->toBe(['missing_credentials', 'missing_credentials']);
});

it('sends our header, not the laravel/mcp one, also when the app uses Mcp::oauthRoutes()', function () {
    sanctumTokenBoot(['runtime-mcp.middleware' => []]);
    Mcp::oauthRoutes();
    Mcp::web('/control-mcp', McpServer::class)->middleware(Answer401::class);
    app('router')->getRoutes()->refreshNameLookups();

    $control = currentTest()->mcpPost('initialize', path: '/control-mcp')->assertUnauthorized();
    expect($control->headers->get('WWW-Authenticate'))->toContain('resource_metadata=');

    sanctumTokenAssertRejected(sanctumTokenPost('Bearer '.str_repeat('z', 40), method: 'initialize'));
});

it('uses the exact ability constant runtime-mcp', function () {
    expect(AuthenticateSanctumToken::ABILITY)->toBe('runtime-mcp');
});

// 01-35 re-review (Sina, 2026-10-30): "user-<id>" must name one user, so the owner must also live in the
// user model's table on its connection. Each owner below is the first row of its table: id 1, the id
// that a user of the users table would also have.
it('accepts a subclass of the user model on its table, and rejects one on another table or connection', function (string $class, ?Reason $reason) {
    sanctumTokenBoot([
        'auth.providers.users.model' => BaseTokenUser::class,
        'database.connections.'.OtherConnectionTokenUser::CONNECTION => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
    ]);

    foreach ([[null, 'token_admins'], [OtherConnectionTokenUser::CONNECTION, 'users']] as [$connection, $table]) {
        Schema::connection($connection)->create($table, function (Blueprint $table): void {
            $table->id();
            $table->string('email')->unique();
            $table->string('password')->default('');
            $table->timestamps();
        });
    }

    $owner = new $class;
    assert($owner instanceof BaseTokenUser);
    $owner->forceFill(['email' => Tokens::EMAIL])->save();

    // The token table is on the default connection; createToken() would write on the owner's.
    $secret = str_repeat('s', 40);
    $row = new PersonalAccessToken;
    $row->forceFill([
        'tokenable_type' => $owner->getMorphClass(),
        'tokenable_id' => $owner->getKey(),
        'name' => 'runtime-mcp',
        'token' => hash('sha256', $secret),
        'abilities' => [AuthenticateSanctumToken::ABILITY],
    ])->save();
    $id = $row->getKey();
    assert(is_int($id));
    $token = $id.'|'.$secret;

    $response = sanctumTokenPost('Bearer '.$token);

    if ($reason === null) {
        $response->assertOk();
        $user = app(CurrentPrincipal::class)->get()?->user;
        expect($user === null ? null : $user::class)->toBe($class);

        return;
    }

    sanctumTokenAssertRejected($response);
    expect(AuditLog::entries()[0]['reason'] ?? null)->toBe($reason->value);
})->with([
    'the user model itself' => [BaseTokenUser::class, null],
    'a subclass on the same table' => [SameTableTokenUser::class, null],
    'a subclass on another table' => [OtherTableTokenUser::class, Reason::TokenOwnerInvalid],
    'a subclass on another connection' => [OtherConnectionTokenUser::class, Reason::TokenOwnerInvalid],
]);
