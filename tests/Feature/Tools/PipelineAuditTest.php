<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use SinaSaeedi\RuntimeMcp\Audit\Events\ToolCalled;
use SinaSaeedi\RuntimeMcp\Audit\Events\ToolDenied;
use SinaSaeedi\RuntimeMcp\Audit\Events\ToolFailed;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\FixtureTools;
use SinaSaeedi\RuntimeMcp\Tests\Support\ProtocolEra;

/*
 * Stage 9 (spec 4.4, 5.7): every call writes exactly one tool_call entry with the real outcome,
 * and never the result data (rule 16).
 */

beforeEach(function () {
    AuditLog::clear();
    FixtureTools::serve();
    Exceptions::fake(); // the failure rows report their exceptions
});

afterEach(fn () => AuditLog::clear());

/** @return array<array-key, mixed> the only audit entry */
function pipelineAuditOnlyEntry(): array
{
    $entries = AuditLog::entries();
    expect($entries)->toHaveCount(1);

    return $entries[0];
}

it('writes one entry for a successful call, with the principal, the raw params and the sizes', function () {
    $structuredText = FixtureTools::text(FixtureTools::call('echo', ['text' => 'hello']), 1);
    $entry = pipelineAuditOnlyEntry();

    expect($entry['kind'])->toBe('tool_call')
        ->and($entry['tool'])->toBe('echo')
        ->and($entry['environment'])->toBe('testing')
        ->and($entry['principal'])->toBe('tests')
        ->and($entry['auth_method'])->toBe('api_key')
        ->and($entry['user_id'])->toBeNull()
        ->and($entry['ip'])->toBe('127.0.0.1')
        ->and($entry['params'])->toBe(['text' => 'hello'])
        ->and($entry['decision'])->toBe('allowed')
        ->and($entry['reason'])->toBeNull()
        ->and($entry['outcome'])->toBe('success')
        ->and($entry['error_class'])->toBeNull()
        ->and($entry['row_count'])->toBe(1)
        // One copy of the envelope JSON (spec 5.8): the structured text is exactly that copy.
        ->and($entry['output_bytes'])->toBe(strlen($structuredText))
        ->and($entry['duration_ms'])->toBeInt()->toBeGreaterThanOrEqual(0);
});

it('records a failure with its class and an error level', function () {
    FixtureTools::call('throwing');
    $entry = pipelineAuditOnlyEntry();
    $record = AuditLog::records()[0];

    expect($entry['decision'])->toBe('allowed')
        ->and($entry['outcome'])->toBe('error')
        ->and($entry['error_class'])->toBe(RuntimeException::class)
        ->and($entry['row_count'])->toBeNull()
        ->and($record['level_name'] ?? null)->toBe('ERROR');
});

it('records a failure to encode as an error with the JSON class', function () {
    FixtureTools::call('result', ['kind' => 'infinite']);

    expect(pipelineAuditOnlyEntry()['error_class'])->toBe(JsonException::class);
});

it('records the row count the tool gave', function (string $kind, ?int $rows) {
    FixtureTools::call('result', ['kind' => $kind]);

    expect(pipelineAuditOnlyEntry()['row_count'])->toBe($rows);
})->with([
    'ok with a count' => ['ok', 2],
    'empty' => ['empty', 0],
    'partial with a count' => ['partial_counted', 1],
    'unavailable' => ['unavailable', null],
    'ok without a count' => ['text', null],
]);

it('never writes the result data', function () {
    FixtureTools::call('result', ['kind' => 'text']); // data: a URL and the name "Zoë"

    $lines = implode("\n", AuditLog::lines());

    expect(AuditLog::lines())->toHaveCount(1)
        ->and(str_contains($lines, 'Zo'))->toBeFalse()
        ->and(str_contains($lines, 'orders'))->toBeFalse();
});

it('records the client of a 2026-07-28 request, and none for a legacy request', function () {
    FixtureTools::call('echo', ['text' => 'a'], ProtocolEra::Modern);
    FixtureTools::call('echo', ['text' => 'b'], ProtocolEra::Legacy);
    $entries = AuditLog::entries();

    expect($entries)->toHaveCount(2)
        ->and($entries[0]['client'])->toBe(['name' => 'runtime-mcp-tests', 'version' => '1.0.0'])
        ->and($entries[1]['client'])->toBeNull();
});

it('dispatches the event of the outcome', function () {
    Event::fake([ToolCalled::class, ToolDenied::class, ToolFailed::class]);

    FixtureTools::call('echo', ['text' => 'a']);
    FixtureTools::call('throwing');

    Event::assertDispatchedTimes(ToolCalled::class, 1);
    Event::assertDispatchedTimes(ToolFailed::class, 1);
    Event::assertNotDispatched(ToolDenied::class);
});
