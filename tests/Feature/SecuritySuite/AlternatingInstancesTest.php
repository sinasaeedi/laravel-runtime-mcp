<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Audit\AuditEntry;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\Gates;
use SinaSaeedi\RuntimeMcp\Tests\Support\ProtocolEra;
use SinaSaeedi\RuntimeMcp\Tests\Support\SecuritySuite;

/*
 * 01-30, spec 11.2 ("Two app instances: requests that alternate between them work"), 3.2 ("No server-side session
 * state"): tools/call on one app instance, then on a new one (a second server behind a load balancer), in turn. The
 * server keeps no session, so every call works on either instance, and each writes its own audit entry. 01-02's
 * TwoInstancesTest proves the same for tools/list.
 */

beforeEach(fn () => SecuritySuite::clear());
afterEach(fn () => SecuritySuite::clear());

it('AI1 answers tools/call on two app instances in turn, and each call writes its own audit entry', function (ProtocolEra $era) {
    SecuritySuite::serve();
    $tools = ['app_info', 'logs_search', 'queue_failed_jobs_list'];

    for ($round = 0; $round < 6; $round++) {
        $tool = $tools[$round % 3];

        expect(SecuritySuite::envelope($tool, $era))->toMatchArray(['environment' => SecuritySuite::ENVIRONMENT, 'status' => 'ok'])
            ->and(AuditLog::entries())->toHaveCount($round + 1);

        // The other server: a new app, the same config and files. Its Gate is defined again (Gates).
        currentTest()->rebootApplication();
        Gates::allow(...array_keys(SecuritySuite::TOOLS));
    }

    expect(array_map(fn (array $entry): array => [$entry['tool'], $entry['decision'], $entry['outcome']], AuditLog::entries()))->toBe([
        ['app_info', AuditEntry::DECISION_ALLOWED, AuditEntry::OUTCOME_SUCCESS],
        ['logs_search', AuditEntry::DECISION_ALLOWED, AuditEntry::OUTCOME_SUCCESS],
        ['queue_failed_jobs_list', AuditEntry::DECISION_ALLOWED, AuditEntry::OUTCOME_SUCCESS],
        ['app_info', AuditEntry::DECISION_ALLOWED, AuditEntry::OUTCOME_SUCCESS],
        ['logs_search', AuditEntry::DECISION_ALLOWED, AuditEntry::OUTCOME_SUCCESS],
        ['queue_failed_jobs_list', AuditEntry::DECISION_ALLOWED, AuditEntry::OUTCOME_SUCCESS],
    ]);
})->with([ProtocolEra::Legacy, ProtocolEra::Modern]);

it('AI2 needs no session header: a call on the second instance needs nothing from the first', function () {
    SecuritySuite::serve();

    $first = SecuritySuite::call('app_info');
    currentTest()->rebootApplication();
    Gates::allow('app_info');
    $second = SecuritySuite::call('app_info');

    expect($first->headers->has('MCP-Session-Id'))->toBeFalse()
        ->and($second->headers->has('MCP-Session-Id'))->toBeFalse()
        ->and(array_column(AuditLog::entries(), 'environment'))->toBe([SecuritySuite::ENVIRONMENT, SecuritySuite::ENVIRONMENT]);
});
