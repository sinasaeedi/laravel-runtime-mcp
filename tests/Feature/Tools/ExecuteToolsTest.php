<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\CatalogCalls;

/*
 * Rule 3, spec 3.2: execute_tools runs many tools in one tools/call and calls ToolInvoker for each. An HTTP middleware
 * sees one request; the pipeline sees every inner call. Since 01-40 these run through the shipped code path: the real
 * catalog.enabled switch, our tools/call handler, and the real endpoint (CatalogCalls).
 */

beforeEach(fn () => AuditLog::clear());
afterEach(fn () => AuditLog::clear());

/** @return array<string, mixed> */
function executeToolsTwoCalls(): array
{
    return CatalogCalls::calls([['echo', ['text' => 'a']], ['second_echo', ['text' => 'b']]]);
}

it('runs the pipeline and writes one audit entry for each inner call, and one for the outer call', function () {
    CatalogCalls::serve();

    $batch = CatalogCalls::batch(CatalogCalls::messages(CatalogCalls::execute(executeToolsTwoCalls())));
    $entries = AuditLog::entries();

    expect($batch['ok'])->toBeTrue()
        ->and($entries)->toHaveCount(3)
        ->and(array_column($entries, 'tool'))->toBe(['echo', 'second_echo', 'execute_tools'])
        ->and(array_column($entries, 'params'))->toBe([['text' => 'a'], ['text' => 'b'], ['names' => ['echo', 'second_echo']]])
        ->and(array_column($entries, 'principal'))->toBe(['tests', 'tests', 'tests'])
        ->and(array_column($entries, 'outcome'))->toBe(['success', 'success', 'success']);
});

it('drops the structured text copy inside the batch, and keeps the label', function () {
    CatalogCalls::serve();

    // The outer text holds each inner result once: as structuredContent, never as structured text.
    $text = (string) json_encode(CatalogCalls::batch(CatalogCalls::messages(CatalogCalls::execute(executeToolsTwoCalls()))));

    expect($text)->toContain('Result from Runtime Test (testing).')
        ->and(substr_count($text, '"environment":"testing"'))->toBe(2);
});

it('refuses the first inner call that the Gate hides before the pipeline, audits it as denied, and the batch stops there', function () {
    // ToolSearch::invokeTool() checks eligibleForRegistration() before ToolInvoker, so our pipeline never sees the call:
    // our tools/call handler writes its entry first (01-40). execute_tools stops at the first result with isError.
    CatalogCalls::serve(allow: ['second_echo']);

    $batch = CatalogCalls::batch(CatalogCalls::messages(CatalogCalls::execute(executeToolsTwoCalls())));

    expect($batch['ok'])->toBeFalse()
        ->and(data_get($batch, 'results.0.content.0.text'))->toBe('Tool [echo] is not available.')
        ->and(CatalogCalls::audit())->toBe([
            ['echo', 'denied', 'no_gate', null],
            ['execute_tools', 'allowed', null, 'error'],
        ])
        ->and(AuditLog::entries()[0]['details'])->toBe(['via' => 'execute_tools']);
});
