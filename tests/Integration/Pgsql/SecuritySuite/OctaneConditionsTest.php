<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use SinaSaeedi\RuntimeMcp\Security\Auth\CurrentPrincipal;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\Databases;
use SinaSaeedi\RuntimeMcp\Tests\Support\FixtureTools;
use SinaSaeedi\RuntimeMcp\Tests\Support\Gates;
use SinaSaeedi\RuntimeMcp\Tests\Support\ReadOnlyRole;
use SinaSaeedi\RuntimeMcp\Tests\Support\SecuritySuite;

/*
 * 01-30, spec 5.13 ("Reset the DB session after each call"; Octane keeps connections open between requests: its
 * DisconnectFromDatabases listener is off by default), on PostgreSQL: one app serves ten requests, with
 * forgetScopedInstances() between them as Octane does. A session setting and an advisory lock of one request are gone
 * in the next, and the read-only connection stays one session object, reset each time.
 */

beforeEach(function () {
    Databases::requiresPostgres();
    AuditLog::clear();
});

afterEach(fn () => AuditLog::clear());

/** @return list<array<array-key, mixed>> the rows of one db_query call */
function octanePgsqlRows(string $sql): array
{
    $rows = data_get(FixtureTools::envelope(FixtureTools::call('db_query', ['sql' => $sql])), 'data.rows');
    assert(is_array($rows));

    return array_values(array_filter($rows, is_array(...)));
}

it('OCP1 leaves no session setting and no advisory lock for the next request in one long-lived app', function () {
    ReadOnlyRole::setUp(currentTest(), 'testing_pgsql', ['runtime-mcp.enabled' => true, 'cache.limiter' => 'array'], SecuritySuite::ENVIRONMENT);
    Gates::allow('db_query');
    $key = random_int(1_000_000, 2_000_000_000);

    for ($round = 0; $round < 10; $round++) {
        // Set in this request: a session-level setting (is_local false) and a session-level advisory lock. The frame's
        // rollback already undoes the setting; only the lock needs DISCARD ALL (mutation 39 fails the lock half only,
        // and ReadOnlySessionTest P7 is the setting proof of DISCARD ALL) (01-30 triage).
        $before = octanePgsqlRows("SELECT current_setting('rmcp.round', true) AS v, pg_try_advisory_lock({$key}) AS got, pg_advisory_unlock({$key}) AS freed");
        octanePgsqlRows("SELECT set_config('rmcp.round', 'round {$round}', false), pg_advisory_lock({$key})");

        expect($before[0][0] ?? null)->toBeIn([null, ''])
            ->and($before[0][1] ?? null)->toBeTrue()
            ->and(DB::connection('testing_pgsql')->scalar("SELECT pg_try_advisory_lock({$key})"))->toBeTrue();
        DB::connection('testing_pgsql')->select('SELECT pg_advisory_unlock_all()');

        app()->forgetScopedInstances();
        expect(app(CurrentPrincipal::class)->get())->toBeNull();
    }

    expect(AuditLog::entries())->toHaveCount(20);
});
