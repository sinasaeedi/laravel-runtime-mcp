<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Doctor\CheckResult;
use SinaSaeedi\RuntimeMcp\Doctor\Checks\AuditLog;
use SinaSaeedi\RuntimeMcp\Doctor\Checks\StatementTimeout;
use SinaSaeedi\RuntimeMcp\Doctor\Doctor;

/* Spec 4.5, 5.2, 7.3, 01-14 Part C: the doctor check for database.statement_timeout_ms (01-12's API). */

/** @return list<array{string, string, string|null}> [status, message, fix] */
function statementTimeoutResults(): array
{
    return array_map(
        fn (CheckResult $result): array => [$result->status->value, $result->message, $result->fix],
        app(StatementTimeout::class)->run(),
    );
}

it('D1 passes with the timeout in force', function (mixed $value, string $message) {
    currentTest()->bootWith(['runtime-mcp.database.statement_timeout_ms' => $value]);

    expect(statementTimeoutResults())->toBe([['pass', $message, null]]);
})->with([
    'the default' => [5000, 'Statements time out after 5000 ms.'],
    'an env() string' => ['2500', 'Statements time out after 2500 ms.'],
]);

it('D2 fails for a bad value, says 5000 is used, and never prints the value', function (mixed $value) {
    currentTest()->bootWith(['runtime-mcp.database.statement_timeout_ms' => $value]);

    expect(statementTimeoutResults())->toBe([[
        'fail',
        'database.statement_timeout_ms is not a whole number from 1 to 2147483647, so 5000 is used.',
        'Set it to the timeout in milliseconds, for example 5000.',
    ]]);
})->with(['zero' => [0], 'negative' => [-250], 'a word' => ['rmcp_secret_value'], 'null' => [null], 'too large' => [3_000_000_000]]);

it('D3 is a plain Fail, not a privilege check: it fails in a relaxed environment too', function () {
    currentTest()->bootWith(['runtime-mcp.database.statement_timeout_ms' => 0], 'local');

    $result = app(StatementTimeout::class)->run()[0];

    expect($result->privilege)->toBeFalse();
});

it('D4 is the core check after AuditLog', function () {
    currentTest()->bootWith();

    expect(array_search(StatementTimeout::class, Doctor::CHECKS, true))->toBe(array_search(AuditLog::class, Doctor::CHECKS, true) + 1)
        ->and(app(StatementTimeout::class)->title())->toBe('Statement timeout');
});
