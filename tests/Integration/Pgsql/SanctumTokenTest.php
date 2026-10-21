<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use SinaSaeedi\RuntimeMcp\Audit\Reason;
use SinaSaeedi\RuntimeMcp\Security\Auth\AuthMethod;
use SinaSaeedi\RuntimeMcp\Security\Auth\CurrentPrincipal;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\Databases;
use SinaSaeedi\RuntimeMcp\Tests\Support\RecordingLogger;
use SinaSaeedi\RuntimeMcp\Tests\Support\Tokens;

/*
 * 01-35 on PostgreSQL: find('abc') on a bigint key throws SQLSTATE 22P02 here (MySQL and SQLite only
 * return no row), so the id guard of AuthenticateSanctumToken is proved on this server. The tables
 * live on testing_pgsql as the default connection, and each test drops them.
 */

const SANCTUM_PGSQL_TABLES = 'DROP TABLE IF EXISTS personal_access_tokens, other_token_owners, users CASCADE';

beforeEach(function () {
    Databases::requiresPostgres();
    AuditLog::clear();
    currentTest()->bootWith(Tokens::config(['database.default' => 'testing_pgsql']));
    DB::statement(SANCTUM_PGSQL_TABLES);
    Tokens::tables();
    currentTest()->beforeApplicationDestroyed(function (): void {
        DB::connection('testing_pgsql')->statement(SANCTUM_PGSQL_TABLES);
    });
});

afterEach(fn () => AuditLog::clear());

it('proves the trap: find() with a non-numeric id throws on PostgreSQL', function () {
    $thrown = null;

    try {
        DB::table('personal_access_tokens')->find('abc');
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown?->getMessage())->toContain('22P02');
});

it('rejects a malformed id with unknown_token and one audit entry, never a 500', function (string $authorization) {
    Exceptions::fake();

    currentTest()->mcpPost('tools/list', headers: ['Authorization' => $authorization], key: null)->assertUnauthorized();

    expect(array_column(AuditLog::entries(), 'reason'))->toBe([Reason::UnknownToken->value]);
    Exceptions::assertNothingReported();
})->with([
    'not a number' => ['Bearer abc|secret'],
    'out of bigint range' => ['Bearer 99999999999999999999|secret'],
]);

it('lets a valid token in on PostgreSQL and sets last_used_at', function () {
    $user = Tokens::user();
    $token = Tokens::issue($user);

    currentTest()->mcpPost('tools/list', key: $token)->assertOk();

    expect(app(CurrentPrincipal::class)->get()?->name)->toBe(Tokens::principalName($user))
        ->and(app(CurrentPrincipal::class)->get()?->method)->toBe(AuthMethod::Sanctum)
        ->and(DB::table('personal_access_tokens')->value('last_used_at'))->not->toBeNull();
});

it('rejects with token_lookup_failed when the token table is missing on PostgreSQL', function () {
    $token = Tokens::issue(Tokens::user());
    DB::statement('DROP TABLE personal_access_tokens');
    // The warning goes to the recorder, never to the Testbench laravel.log.
    $log = RecordingLogger::install();
    Exceptions::fake();

    currentTest()->mcpPost('tools/list', key: $token)->assertUnauthorized();

    expect(array_column(AuditLog::entries(), 'reason'))->toBe([Reason::TokenLookupFailed->value])
        ->and($log->messages())->toBe(['Runtime MCP: the Sanctum token lookup failed (Illuminate\Database\QueryException), so the request was rejected.']);
    Exceptions::assertNothingReported();
});
