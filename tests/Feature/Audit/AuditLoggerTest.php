<?php

declare(strict_types=1);

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use SinaSaeedi\RuntimeMcp\Audit\AuditEntry;
use SinaSaeedi\RuntimeMcp\Audit\AuditLogger;
use SinaSaeedi\RuntimeMcp\Audit\AuditUnavailable;
use SinaSaeedi\RuntimeMcp\Audit\Events\AuthFailed;
use SinaSaeedi\RuntimeMcp\Audit\Events\ToolCalled;
use SinaSaeedi\RuntimeMcp\Audit\Events\ToolDenied;
use SinaSaeedi\RuntimeMcp\Audit\Events\ToolFailed;
use SinaSaeedi\RuntimeMcp\Audit\Reason;
use SinaSaeedi\RuntimeMcp\Security\Redaction\InvalidRedactionPattern;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;

/*
 * Pest test-file functions are global, so each helper name starts with "auditLogger".
 * Secret samples are built by concatenation, so GitHub push protection never sees a whole key.
 */

beforeEach(fn () => AuditLog::clear());
afterEach(fn () => AuditLog::clear());

/** @param array<array-key, mixed> $params */
function auditLoggerCall(array $params = ['sql' => 'select 1'], bool $allowed = true, bool $failed = false): AuditEntry
{
    return AuditEntry::toolCall(
        environment: 'testing',
        tool: 'db_query',
        params: $params,
        allowed: $allowed,
        reason: $allowed ? null : Reason::GateDenied,
        failed: $failed,
        errorClass: $failed ? RuntimeException::class : null,
        durationMs: 5,
        principal: 'sina',
        authMethod: 'api_key',
        ip: '10.0.0.5',
    );
}

function auditLoggerRejection(): AuditEntry
{
    return AuditEntry::rejectedRequest('testing', Reason::UnknownKey, 'api_key', '203.0.113.9', ['scheme' => 'Bearer']);
}

/**
 * An app-defined channel whose directory cannot be created: its parent is a regular file. A chmod
 * would not stop root (a Docker CI job); this does.
 *
 * @return array<string, mixed>
 */
function auditLoggerBrokenChannel(): array
{
    return ['logging.channels.runtime-mcp' => [
        'driver' => 'daily',
        'path' => __FILE__.'/no-such-dir/audit.log',
        'formatter' => Monolog\Formatter\JsonFormatter::class,
    ]];
}

/** @return array<array-key, mixed> the params of the only line in the audit file */
function auditLoggerStoredParams(): array
{
    $entries = AuditLog::entries();
    expect($entries)->toHaveCount(1);

    $params = $entries[0]['params'] ?? null;
    assert(is_array($params));

    return $params;
}

it('writes a tool call at its level and dispatches its event with the same entry', function (bool $allowed, bool $failed, string $level, string $event) {
    Event::fake([ToolCalled::class, ToolDenied::class, ToolFailed::class, AuthFailed::class]);

    app(AuditLogger::class)->toolCall(auditLoggerCall(allowed: $allowed, failed: $failed));

    $records = AuditLog::records();

    expect($records)->toHaveCount(1)
        ->and($records[0]['message'])->toBe('runtime-mcp.tool_call')
        ->and($records[0]['level_name'])->toBe($level);

    Event::assertDispatchedTimes($event, 1);
    Event::assertDispatched($event, fn (ToolCalled|ToolDenied|ToolFailed $e): bool => $e->entry->toArray() === $records[0]['context']);

    foreach (array_diff([ToolCalled::class, ToolDenied::class, ToolFailed::class, AuthFailed::class], [$event]) as $other) {
        Event::assertNotDispatched($other);
    }
})->with([
    'allowed, success' => [true, false, 'INFO', ToolCalled::class],
    'denied' => [false, false, 'WARNING', ToolDenied::class],
    'denied, even when marked failed' => [false, true, 'WARNING', ToolDenied::class],
    'allowed, error' => [true, true, 'ERROR', ToolFailed::class],
]);

it('writes a rejected request at warning and dispatches AuthFailed', function () {
    Event::fake([ToolCalled::class, ToolDenied::class, ToolFailed::class, AuthFailed::class]);

    $entry = auditLoggerRejection();
    app(AuditLogger::class)->rejectedRequest($entry);

    $records = AuditLog::records();

    expect($records)->toHaveCount(1)
        ->and($records[0]['message'])->toBe('runtime-mcp.request_rejected')
        ->and($records[0]['level_name'])->toBe('WARNING')
        ->and($records[0]['context'])->toBe($entry->toArray());

    Event::assertDispatched(AuthFailed::class, fn (AuthFailed $e): bool => $e->entry->toArray() === $records[0]['context']);
    Event::assertNotDispatched(ToolDenied::class);
});

it('stores exactly the 18 fields of Part B and nothing from the request', function () {
    app(AuditLogger::class)->toolCall(auditLoggerCall());

    $entries = AuditLog::entries();

    expect(array_keys($entries[0]))->toBe([
        'kind', 'timestamp', 'environment', 'principal', 'user_id', 'auth_method', 'ip', 'client',
        'tool', 'params', 'decision', 'reason', 'outcome', 'error_class', 'duration_ms', 'row_count',
        'output_bytes', 'details',
    ]);
});

it('redacts the params in the file and in the event', function () {
    Event::fake([ToolCalled::class]);

    $jwt = 'eyJ'.'hbGciOiJIUzI1NiJ9.'.'eyJ'.'zdWIiOiIxMjM0NTY3ODkwIn0.SflKxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c';
    $params = [
        'sql' => "select * from logs where token = '{$jwt}'",
        'nested' => ['header' => 'Authorization: Bearer '.str_repeat('a1B2', 8)],
        'url' => 'mysql://app:'.'s3cret-pw'.'@db.internal/app',
    ];

    app(AuditLogger::class)->toolCall(auditLoggerCall($params));

    $expected = [
        'sql' => "select * from logs where token = '[redacted:jwt]'",
        'nested' => ['header' => 'Authorization: Bearer [redacted:bearer]'],
        'url' => 'mysql://app:[redacted:password]@db.internal/app',
    ];

    expect(auditLoggerStoredParams())->toBe($expected)
        ->and(str_contains(implode("\n", AuditLog::lines()), 's3cret-pw'))->toBeFalse('A URL password reached the audit file.');

    Event::assertDispatched(ToolCalled::class, fn (ToolCalled $e): bool => $e->entry->params === $expected);
});

it('writes the entry before any listener runs', function () {
    $linesSeen = [];

    Event::listen(ToolCalled::class, function () use (&$linesSeen): void {
        $linesSeen[] = count(AuditLog::lines());
    });

    app(AuditLogger::class)->toolCall(auditLoggerCall());

    expect($linesSeen)->toBe([1]);
});

it('reports a listener that throws and still returns normally', function () {
    Exceptions::fake();

    Event::listen(ToolCalled::class, fn () => throw new RuntimeException('listener down'));
    Event::listen(AuthFailed::class, fn () => throw new RuntimeException('auth listener down'));

    $logger = app(AuditLogger::class);
    $logger->toolCall(auditLoggerCall());
    $logger->rejectedRequest(auditLoggerRejection());

    expect(AuditLog::lines())->toHaveCount(2);

    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'listener down');
    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'auth listener down');
});

it('throws AuditUnavailable with a fixed message when a tool call cannot be written', function () {
    currentTest()->bootWith(auditLoggerBrokenChannel());
    Event::fake([ToolCalled::class]);

    $thrown = null;

    try {
        app(AuditLogger::class)->toolCall(auditLoggerCall());
    } catch (AuditUnavailable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(AuditUnavailable::class)
        ->and($thrown?->getMessage())->toBe('The audit log could not be written.')
        ->and($thrown?->getPrevious())->toBeInstanceOf(UnexpectedValueException::class);

    Event::assertNotDispatched(ToolCalled::class);
});

it('throws AuditUnavailable when the Redactor cannot be built', function () {
    currentTest()->bootWith(['runtime-mcp.redaction.extra_patterns' => ['/(/']]);

    $logger = app(AuditLogger::class);
    $thrown = null;

    try {
        $logger->toolCall(auditLoggerCall());
    } catch (AuditUnavailable $e) {
        $thrown = $e;
    }

    expect($thrown?->getPrevious())->toBeInstanceOf(InvalidRedactionPattern::class)
        ->and(AuditLog::lines())->toBe([]);
});

/** @param array<string, mixed> $config */
function auditLoggerAssertRejectionReported(array $config): void
{
    currentTest()->bootWith($config);
    Exceptions::fake();
    Event::fake([AuthFailed::class]);

    app(AuditLogger::class)->rejectedRequest(auditLoggerRejection());

    Exceptions::assertReported(AuditUnavailable::class);
    Event::assertNotDispatched(AuthFailed::class);
}

it('reports a rejected request that cannot be written, and does not throw', function () {
    auditLoggerAssertRejectionReported(auditLoggerBrokenChannel());
});

it('reports a rejected request when the Redactor cannot be built, and does not throw', function () {
    auditLoggerAssertRejectionReported(['runtime-mcp.redaction.extra_patterns' => ['/(/']]);
});

// Laravel's Logger writes, then fires MessageLogged inside the same call. A MessageLogged listener
// that throws therefore withholds the result, although the line is on disk (errs closed, Part C).
it('treats a throwing MessageLogged listener as a failed write', function () {
    Event::listen(MessageLogged::class, fn () => throw new RuntimeException('telescope down'));

    expect(fn () => app(AuditLogger::class)->toolCall(auditLoggerCall()))->toThrow(AuditUnavailable::class)
        ->and(AuditLog::lines())->toHaveCount(1);
});

it('writes to the channel named in audit.channel', function () {
    currentTest()->bootWith([
        'runtime-mcp.audit.channel' => 'security',
        'logging.channels.security' => [
            'driver' => 'single',
            'path' => storage_path('logs/runtime-mcp-security-test.log'),
            'formatter' => Monolog\Formatter\JsonFormatter::class,
        ],
    ]);

    try {
        app(AuditLogger::class)->rejectedRequest(auditLoggerRejection());

        expect(AuditLog::entries('runtime-mcp-security-test.log'))->toHaveCount(1)
            ->and(AuditLog::files('runtime-mcp-2*.log'))->toBe([]);
    } finally {
        @unlink(storage_path('logs/runtime-mcp-security-test.log'));
    }
});

it('keeps params of exactly 16,384 bytes and cuts one byte more', function () {
    // {"q":"…"} adds 8 bytes around the string.
    app(AuditLogger::class)->toolCall(auditLoggerCall(['q' => str_repeat('a', 16_376)]));
    expect(auditLoggerStoredParams())->toBe(['q' => str_repeat('a', 16_376)]);

    AuditLog::clear();

    app(AuditLogger::class)->toolCall(auditLoggerCall(['q' => str_repeat('a', 16_377)]));
    expect(auditLoggerStoredParams())->toBe(['q' => str_repeat('a', 1_024).'…[truncated 15353 bytes]']);
});

it('cuts only strings longer than 1,024 bytes, also nested ones', function () {
    $params = [
        'keep' => str_repeat('k', 1_024),
        'cut' => str_repeat('c', 1_025),
        'nested' => ['deep' => str_repeat('d', 20_000)],
        'number' => 5,
    ];

    app(AuditLogger::class)->toolCall(auditLoggerCall($params));

    expect(auditLoggerStoredParams())->toBe([
        'keep' => str_repeat('k', 1_024),
        'cut' => str_repeat('c', 1_024).'…[truncated 1 bytes]',
        'nested' => ['deep' => str_repeat('d', 1_024).'…[truncated 18976 bytes]'],
        'number' => 5,
    ]);
});

// Fifteen such cut strings fit in 16 KB (each is 1,024 bytes plus the marker); sixteen go to step 4.
it('cuts ten 15 KB strings each, and the line stays valid JSON', function () {
    $params = array_fill_keys(array_map(fn (int $i): string => "p{$i}", range(1, 10)), str_repeat('x', 15_000));

    app(AuditLogger::class)->toolCall(auditLoggerCall($params));

    $stored = auditLoggerStoredParams();

    expect(array_values($stored))->toBe(array_fill(0, 10, str_repeat('x', 1_024).'…[truncated 13976 bytes]'));
});

it('replaces params that are still too large after the cuts', function () {
    $params = ['ids' => array_fill(0, 10_000, 'ab')];
    $size = strlen((string) json_encode($params));

    app(AuditLogger::class)->toolCall(auditLoggerCall($params));

    expect(auditLoggerStoredParams())->toBe(['_truncated' => "[params of {$size} bytes over the 16384-byte cap]"]);
});

it('never splits a multibyte character when it cuts', function () {
    app(AuditLogger::class)->toolCall(auditLoggerCall(['q' => str_repeat('€', 6_000)]));

    // 1,024 bytes hold 341 three-byte characters (1,023 bytes); 18,000 - 1,023 = 16,977 are cut.
    expect(auditLoggerStoredParams())->toBe(['q' => str_repeat('€', 341).'…[truncated 16977 bytes]']);
});

it('keeps invalid UTF-8 from a log line as valid JSON', function () {
    app(AuditLogger::class)->toolCall(auditLoggerCall(['contains' => "bad \xB1\x31 byte"]));

    expect(auditLoggerStoredParams())->toBe(['contains' => "bad \u{FFFD}1 byte"]);
});

it('is a scoped binding', function () {
    $first = app(AuditLogger::class);

    expect(app(AuditLogger::class))->toBe($first);

    app()->forgetScopedInstances();

    expect(app(AuditLogger::class))->not->toBe($first);
});
