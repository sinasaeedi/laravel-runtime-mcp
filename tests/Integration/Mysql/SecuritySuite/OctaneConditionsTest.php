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
 * 01-30, spec 5.13 ("Reset the DB session after each call"), on MySQL 8.4 and MariaDB: one app serves ten requests, with
 * forgetScopedInstances() between them as Octane does. A user variable and a named lock of one request are gone in the
 * next: the reset disconnects (01-14).
 */

beforeEach(function () {
    Databases::requiresMysql();
    AuditLog::clear();
});

afterEach(fn () => AuditLog::clear());

/** @return list<array<array-key, mixed>> */
function octaneMysqlRows(string $sql): array
{
    $rows = data_get(FixtureTools::envelope(FixtureTools::call('db_query', ['sql' => $sql])), 'data.rows');
    assert(is_array($rows));

    return array_values(array_filter($rows, is_array(...)));
}

it('OCM1 leaves no user variable and no named lock for the next request in one long-lived app', function () {
    ReadOnlyRole::setUp(currentTest(), 'testing_mysql', ['runtime-mcp.enabled' => true, 'cache.limiter' => 'array'], SecuritySuite::ENVIRONMENT);
    Gates::allow('db_query');
    $lock = 'rmcp_'.bin2hex(random_bytes(4));

    for ($round = 0; $round < 10; $round++) {
        $before = octaneMysqlRows("SELECT @rmcp_round AS v, IS_FREE_LOCK('{$lock}') AS free");
        octaneMysqlRows("SELECT @rmcp_round := 'round {$round}' AS v, GET_LOCK('{$lock}', 0) AS l");

        expect($before)->toBe([[null, 1]])
            ->and(DB::connection('testing_mysql')->scalar("SELECT IS_FREE_LOCK('{$lock}')"))->toBe(1);

        app()->forgetScopedInstances();
        expect(app(CurrentPrincipal::class)->get())->toBeNull();
    }

    expect(AuditLog::entries())->toHaveCount(20);
});
