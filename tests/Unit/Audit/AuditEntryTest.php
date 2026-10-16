<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use SinaSaeedi\RuntimeMcp\Audit\AuditEntry;
use SinaSaeedi\RuntimeMcp\Audit\Reason;

beforeEach(function () {
    Carbon::setTestNow('2026-10-02T14:03:11+01:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

/** @return list<string> the 18 fields of Part B, in the order of toArray() */
function auditEntryFields(): array
{
    return [
        'kind', 'timestamp', 'environment', 'principal', 'user_id', 'auth_method', 'ip', 'client',
        'tool', 'params', 'decision', 'reason', 'outcome', 'error_class', 'duration_ms', 'row_count',
        'output_bytes', 'details',
    ];
}

it('builds an allowed, successful tool call with exactly the fields of Part B', function () {
    $entry = AuditEntry::toolCall(
        environment: 'staging',
        tool: 'db_query',
        params: ['sql' => 'select 1'],
        allowed: true,
        durationMs: 12,
        rowCount: 1,
        outputBytes: 240,
        principal: 'sina',
        userId: 7,
        authMethod: 'api_key',
        ip: '10.0.0.5',
        client: ['name' => 'claude-code', 'version' => '2.1.0'],
    );

    expect($entry->toArray())->toBe([
        'kind' => 'tool_call',
        'timestamp' => '2026-10-02T13:03:11+00:00',
        'environment' => 'staging',
        'principal' => 'sina',
        'user_id' => 7,
        'auth_method' => 'api_key',
        'ip' => '10.0.0.5',
        'client' => ['name' => 'claude-code', 'version' => '2.1.0'],
        'tool' => 'db_query',
        'params' => ['sql' => 'select 1'],
        'decision' => 'allowed',
        'reason' => null,
        'outcome' => 'success',
        'error_class' => null,
        'duration_ms' => 12,
        'row_count' => 1,
        'output_bytes' => 240,
        'details' => [],
    ])
        ->and(array_keys($entry->toArray()))->toBe(auditEntryFields());
});

it('gives a denied call its reason and no outcome or error class', function () {
    $entry = AuditEntry::toolCall(
        environment: 'production',
        tool: 'db_query',
        params: [],
        allowed: false,
        reason: Reason::NoGate,
        failed: true,
        errorClass: RuntimeException::class,
    );

    expect($entry->decision)->toBe('denied')
        ->and($entry->reason)->toBe('no_gate')
        ->and($entry->outcome)->toBeNull()
        ->and($entry->errorClass)->toBeNull();
});

it('gives a failed call the outcome error and keeps the error class', function () {
    $entry = AuditEntry::toolCall(
        environment: 'production',
        tool: 'db_query',
        params: [],
        allowed: true,
        reason: Reason::GateDenied,
        failed: true,
        errorClass: RuntimeException::class,
    );

    expect($entry->decision)->toBe('allowed')
        ->and($entry->reason)->toBeNull()
        ->and($entry->outcome)->toBe('error')
        ->and($entry->errorClass)->toBe(RuntimeException::class);
});

it('drops the error class of a successful call', function () {
    $entry = AuditEntry::toolCall(
        environment: 'production',
        tool: 'db_query',
        params: [],
        allowed: true,
        errorClass: RuntimeException::class,
    );

    expect($entry->outcome)->toBe('success')
        ->and($entry->errorClass)->toBeNull();
});

it('builds a rejected request with only the HTTP facts', function () {
    $entry = AuditEntry::rejectedRequest(
        environment: 'production',
        reason: Reason::UnsupportedScheme,
        authMethod: 'api_key',
        ip: '203.0.113.9',
        details: ['scheme' => 'Basic'],
    );

    expect($entry->toArray())->toBe([
        'kind' => 'request_rejected',
        'timestamp' => '2026-10-02T13:03:11+00:00',
        'environment' => 'production',
        'principal' => null,
        'user_id' => null,
        'auth_method' => 'api_key',
        'ip' => '203.0.113.9',
        'client' => null,
        'tool' => null,
        'params' => [],
        'decision' => 'denied',
        'reason' => 'unsupported_scheme',
        'outcome' => null,
        'error_class' => null,
        'duration_ms' => null,
        'row_count' => null,
        'output_bytes' => null,
        'details' => ['scheme' => 'Basic'],
    ]);
});

it('writes the timestamp as ISO 8601 with the offset of the app timezone', function () {
    $timezone = date_default_timezone_get();
    date_default_timezone_set('Asia/Tehran');

    try {
        $timestamp = AuditEntry::rejectedRequest('production', Reason::BadHost, null, null)->timestamp;
    } finally {
        date_default_timezone_set($timezone);
    }

    expect($timestamp)->toBe('2026-10-02T16:33:11+03:30');
});

it('cuts the tool name and each details string to 255 characters, by character', function () {
    $entry = AuditEntry::toolCall(
        environment: 'production',
        tool: str_repeat('é', 300),
        params: [],
        allowed: false,
        reason: Reason::NoGate,
        details: ['host' => str_repeat('ü', 300), 'inner_calls' => 3],
    );

    expect($entry->tool)->toBe(str_repeat('é', 255))
        ->and($entry->details)->toBe(['host' => str_repeat('ü', 255), 'inner_calls' => 3]);

    $rejected = AuditEntry::rejectedRequest('production', Reason::BadOrigin, null, null, ['origin' => str_repeat('o', 256)]);

    expect($rejected->details)->toBe(['origin' => str_repeat('o', 255)]);
});

it('replaces only the params in withParams()', function () {
    $entry = AuditEntry::toolCall(
        environment: 'staging',
        tool: 'db_query',
        params: ['sql' => 'raw'],
        allowed: true,
        principal: 'sina',
        details: ['via' => 'execute_tools'],
    );

    $stored = $entry->withParams(['sql' => 'redacted']);

    expect($stored->params)->toBe(['sql' => 'redacted'])
        ->and(array_diff_key($stored->toArray(), ['params' => true]))
        ->toBe(array_diff_key($entry->toArray(), ['params' => true]))
        ->and($entry->params)->toBe(['sql' => 'raw']);
});

// Rule 16: no field can hold result data. A new property fails this test, so a later story must
// change it on purpose and say why.
it('has exactly the 18 fields and a private constructor (rule 16)', function () {
    $class = new ReflectionClass(AuditEntry::class);
    $constructor = $class->getConstructor();

    $properties = array_map(fn (ReflectionProperty $p): string => $p->getName(), $class->getProperties());
    $parameters = $constructor === null ? [] : array_map(
        fn (ReflectionParameter $p): string => $p->getName(),
        $constructor->getParameters(),
    );

    $camel = array_map(fn (string $field): string => lcfirst(str_replace('_', '', ucwords($field, '_'))), auditEntryFields());

    expect($properties)->toBe($camel)
        ->and($parameters)->toBe($camel)
        ->and($constructor?->isPrivate())->toBeTrue()
        ->and(array_intersect($properties, ['data', 'rows', 'result', 'content', 'output', 'body', 'response', 'payload']))->toBe([]);
});
