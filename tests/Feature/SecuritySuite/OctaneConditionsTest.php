<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use SinaSaeedi\RuntimeMcp\Audit\AuditEntry;
use SinaSaeedi\RuntimeMcp\Security\Auth\CurrentPrincipal;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\OctaneWorker;
use SinaSaeedi\RuntimeMcp\Tests\Support\SecuritySuite;
use SinaSaeedi\RuntimeMcp\Tests\TestCase;

/*
 * 01-30, spec 5.13 ("Test under Octane conditions where possible"), rule 14: one app serves many requests.
 * OC1 simulates Octane in the test's app (forgetScopedInstances() between requests, as Octane's
 * FlushTemporaryContainerInstances listener does); OC2 runs the same sequence through a real Octane worker
 * (laravel/octane's FakeWorker: one booted app, a clone of it per request, Octane's own listeners). The PostgreSQL
 * session reset across requests is OCP (tests/Integration/Pgsql/SecuritySuite/OctaneConditionsTest.php).
 */

beforeEach(fn () => SecuritySuite::clear());
afterEach(fn () => SecuritySuite::clear());

const OCTANE_ALICE = 'rmcp_'.'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

const OCTANE_BOB = 'rmcp_'.'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB';

/** The api_keys value with alice, bob and the default test key. */
function octaneKeys(): string
{
    return TestCase::defaultKeyEntry().',alice:'.hash('sha256', OCTANE_ALICE).',bob:'.hash('sha256', OCTANE_BOB);
}

/**
 * Round n: alice, bob, then a request with no key, in turn.
 *
 * @return array{string|null, string|null} [key, principal]
 */
function octaneRound(int $round): array
{
    return [[OCTANE_ALICE, 'alice'], [OCTANE_BOB, 'bob'], [null, null]][$round % 3];
}

it('OC1 gives each request of one app its own principal, keeps none after it, and leaves the default connection writable', function () {
    SecuritySuite::serve(['runtime-mcp.auth.api_keys' => octaneKeys()]);
    $tools = ['app_info', 'logs_search', 'queue_failed_jobs_list', 'erp_order', 'app_order_lookup'];
    $rows = DB::connection('testing')->table('ro_orders')->count();

    for ($round = 0; $round < 10; $round++) {
        [$key, $principal] = octaneRound($round);
        $tool = $tools[$round % count($tools)];
        $params = ['name' => $tool, 'arguments' => (object) SecuritySuite::TOOLS[$tool][0]];
        $response = currentTest()->mcpPost('tools/call', $params, key: $key);
        $entry = AuditLog::entries()[$round];

        if ($key === null) {
            $response->assertUnauthorized();
            expect($entry)->toMatchArray(['kind' => AuditEntry::KIND_REQUEST_REJECTED, 'principal' => null]);
        } else {
            $response->assertOk();
            expect($entry)->toMatchArray(['tool' => $tool, 'principal' => $principal, 'decision' => AuditEntry::DECISION_ALLOWED]);
        }

        // What Octane does after each request (FlushTemporaryContainerInstances).
        app()->forgetScopedInstances();

        expect(app(CurrentPrincipal::class)->get())->toBeNull()
            ->and(DB::connection('testing')->transactionLevel())->toBe(0)
            ->and(DB::connection('testing')->scalar('PRAGMA query_only'))->toBe(0)
            ->and(DB::connection('testing')->table('ro_orders')->insert(['n' => 'round '.$round]))->toBeTrue();
    }

    expect(AuditLog::entries())->toHaveCount(10)
        ->and(DB::connection('testing')->table('ro_orders')->count())->toBe($rows + 10);
});

it('OC2 gives each request of a real Octane worker its own principal and its own audit entry, and leaves its default connection writable', function () {
    AuditLog::clear();
    $requests = [];
    $connection = [];

    for ($round = 0; $round < 10; $round++) {
        $requests[] = OctaneWorker::call($round + 1, 'app_info', octaneRound($round)[0] ?? '');
    }

    // Octane warms `db`: every request clone shares the worker's connections, so a read-only default would carry over.
    $responses = OctaneWorker::serve($requests, ['runtime-mcp.auth.api_keys' => octaneKeys()], ['app_info'], function (Application $worker) use (&$connection): void {
        $testing = $worker->make(DatabaseManager::class)->connection('testing');
        $connection = [$testing->transactionLevel(), $testing->scalar('PRAGMA query_only')];
    });
    $entries = AuditLog::entries();

    expect($connection)->toBe([0, 0])
        ->and(array_map(fn ($response): int => $response->getStatusCode(), $responses))->toBe([200, 200, 401, 200, 200, 401, 200, 200, 401, 200])
        ->and(array_column($entries, 'principal'))->toBe(['alice', 'bob', null, 'alice', 'bob', null, 'alice', 'bob', null, 'alice'])
        ->and(array_column($entries, 'kind'))->toBe([
            AuditEntry::KIND_TOOL_CALL, AuditEntry::KIND_TOOL_CALL, AuditEntry::KIND_REQUEST_REJECTED,
            AuditEntry::KIND_TOOL_CALL, AuditEntry::KIND_TOOL_CALL, AuditEntry::KIND_REQUEST_REJECTED,
            AuditEntry::KIND_TOOL_CALL, AuditEntry::KIND_TOOL_CALL, AuditEntry::KIND_REQUEST_REJECTED,
            AuditEntry::KIND_TOOL_CALL,
        ]);

    foreach ([0, 1, 3, 4, 6, 7, 9] as $i) {
        expect(json_decode((string) $responses[$i]->getContent(), true))->toHaveKey('result.structuredContent.environment', 'testing');
    }
});
