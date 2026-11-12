<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use SinaSaeedi\RuntimeMcp\Plugins\Logs\ErrorFingerprint;
use SinaSaeedi\RuntimeMcp\Plugins\Logs\ErrorGroups;
use SinaSaeedi\RuntimeMcp\Plugins\Logs\LogEntry;
use SinaSaeedi\RuntimeMcp\Plugins\Logs\LogSource;
use SinaSaeedi\RuntimeMcp\Security\Redaction\Redactor;

/*
 * 01-26 Part B: the grouping that LogsRecentErrors feeds from LogScan's keep closure. The tool's statuses and notes
 * are LogsRecentErrorsTest's.
 */

function errorGroups(): ErrorGroups
{
    return new ErrorGroups(app(Redactor::class));
}

/** @param  string|array<array-key, mixed>|null  $context */
function errorEntry(string $time, string $level, string $message, string|array|null $context = null): LogEntry
{
    return new LogEntry(CarbonImmutable::parse($time), $level, 'testing', $message, $context);
}

function errorSource(string $channel = 'app_single'): LogSource
{
    return new LogSource($channel, '/tmp/app.log', LogSource::LINE, false);
}

it('EG1 groups by cause with the count, first and last seen, and the newest entry as the sample', function () {
    $groups = errorGroups();
    $groups->add(errorEntry('2026-10-02 09:05:00', 'error', 'Order 3 failed', 'newest'), errorSource(), 'app.log');
    $groups->add(errorEntry('2026-10-02 09:04:00', 'error', 'Payment declined'), errorSource(), 'app.log');
    $groups->add(errorEntry('2026-10-02 09:03:00', 'error', 'Order 2 failed', 'older'), errorSource(), 'app.log');
    $groups->add(errorEntry('2026-10-02 09:01:00', 'error', 'Order 1 failed', 'oldest'), errorSource(), 'app.log');

    expect($groups->count())->toBe(2)
        ->and($groups->top(10)[0])->toBe([
            'fingerprint' => ErrorFingerprint::of('error', 'Order {n} failed'),
            'level' => 'error',
            'pattern' => 'Order {n} failed',
            'count' => 3,
            'first_seen' => '2026-10-02T09:01:00+00:00',
            'last_seen' => '2026-10-02T09:05:00+00:00',
            'sample' => [
                'time' => '2026-10-02T09:05:00+00:00',
                'level' => 'error',
                'env' => 'testing',
                'channel' => 'app_single',
                'file' => 'app.log',
                'message' => 'Order 3 failed',
                'context' => 'newest',
            ],
        ])
        ->and($groups->top(10)[1])->toMatchArray(['pattern' => 'Payment declined', 'count' => 1]);
});

it('EG2 keeps the first and last time and the newest sample when lines come out of order', function () {
    $groups = errorGroups();
    $groups->add(errorEntry('2026-10-02 09:05:00', 'error', 'Order 5 failed'), errorSource(), 'app.log');
    $groups->add(errorEntry('2026-10-02 09:01:00', 'error', 'Order 1 failed'), errorSource(), 'app.log');
    $groups->add(errorEntry('2026-10-02 09:07:00', 'error', 'Order 7 failed'), errorSource('app_json'), 'app-json.log');
    $groups->add(errorEntry('2026-10-02 09:03:00', 'error', 'Order 3 failed'), errorSource(), 'app.log');

    expect($groups->top(1)[0])->toMatchArray([
        'count' => 4,
        'first_seen' => '2026-10-02T09:01:00+00:00',
        'last_seen' => '2026-10-02T09:07:00+00:00',
    ])->and($groups->top(1)[0]['sample'])->toMatchArray(['message' => 'Order 7 failed', 'channel' => 'app_json', 'file' => 'app-json.log']);
});

it('EG3 groups error and higher only, by level, and counts every entry it reads', function () {
    $groups = errorGroups();

    foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency', 'error'] as $minute => $level) {
        $groups->add(errorEntry(sprintf('2026-10-02 09:%02d:00', 10 - $minute), $level, 'Disk full'), errorSource(), 'app.log');
    }

    expect($groups->read())->toBe(9)
        ->and($groups->count())->toBe(4)
        ->and(array_column($groups->top(10), 'level'))->toBe(['error', 'critical', 'alert', 'emergency'])
        ->and(array_column($groups->top(10), 'count'))->toBe([2, 1, 1, 1]);
});

it('EG4 orders by count, then last seen, then fingerprint, and keeps at most the limit', function () {
    $groups = errorGroups();
    // "A" twice; "B" and "C" once at the same time; "D" once, newer.
    foreach ([['09:01', 'A'], ['09:02', 'A'], ['09:03', 'B'], ['09:03', 'C'], ['09:04', 'D']] as [$time, $message]) {
        $groups->add(errorEntry("2026-10-02 {$time}:00", 'error', "Cause {$message}"), errorSource(), 'app.log');
    }

    $ties = [ErrorFingerprint::of('error', 'Cause B'), ErrorFingerprint::of('error', 'Cause C')];
    sort($ties);

    expect(array_column($groups->top(10), 'fingerprint'))->toBe([
        ErrorFingerprint::of('error', 'Cause A'),
        ErrorFingerprint::of('error', 'Cause D'),
        ...$ties,
    ])
        ->and($groups->top(2))->toHaveCount(2)
        ->and($groups->count())->toBe(4);
});

it('EG5 gives the cut time as the newest of the oldest times per source, or none when a source gave no entry', function () {
    $groups = errorGroups();

    expect($groups->cutTime(1))->toBeNull();

    $groups->add(errorEntry('2026-10-02 09:30:00', 'info', 'a'), errorSource('app_single'), 'app.log');
    $groups->add(errorEntry('2026-10-02 09:10:00', 'info', 'b'), errorSource('app_single'), 'app.log');

    expect($groups->cutTime(1)?->toIso8601String())->toBe('2026-10-02T09:10:00+00:00')
        ->and($groups->cutTime(2))->toBeNull()
        ->and($groups->cutTime(0))->toBeNull();

    $groups->add(errorEntry('2026-10-02 07:00:00', 'info', 'c'), errorSource('app_json'), 'app-json.log');

    expect($groups->cutTime(2)?->toIso8601String())->toBe('2026-10-02T09:10:00+00:00');
});

it('EG6 takes the pattern from the redacted message, so a secret never reaches it and secrets do not split a cause', function () {
    // The Stripe-like keys are split in two so that secret scanners do not flag this fake test data. 12 characters
    // after sk_live_: too short for {id}, so a raw pattern would keep "eC" and "HqLyjWD".
    $groups = errorGroups();
    $groups->add(errorEntry('2026-10-02 09:02:00', 'error', 'Stripe call with sk_'.'live_4eC39HqLyjWD failed'), errorSource(), 'app.log');
    $groups->add(errorEntry('2026-10-02 09:01:00', 'error', 'Stripe call with sk_'.'live_9xQ81LmPcdTy failed'), errorSource(), 'app.log');

    $top = $groups->top(10);

    expect($top)->toHaveCount(1)
        ->and($top[0]['pattern'])->toBe('Stripe call with [redacted:stripe_key] failed')
        ->and($top[0]['count'])->toBe(2)
        ->and($top[0]['sample']['message'])->toBe('Stripe call with [redacted:stripe_key] failed')
        ->and((string) json_encode($top))->not->toContain('HqLyjWD')
        ->and((string) json_encode($top))->not->toContain('LmPcdTy');
});

it('EG7 cuts the shown pattern to 300 characters, and hashes the 500-character pattern', function () {
    $groups = errorGroups();
    $groups->add(errorEntry('2026-10-02 09:00:00', 'error', str_repeat('x', 400).' a'), errorSource(), 'app.log');
    $groups->add(errorEntry('2026-10-02 08:59:00', 'error', str_repeat('x', 400).' b'), errorSource(), 'app.log');

    $top = $groups->top(10);

    expect($top)->toHaveCount(2)
        ->and(mb_strlen($top[0]['pattern']))->toBe(ErrorGroups::SHOWN_PATTERN)
        ->and($top[0]['pattern'])->toBe($top[1]['pattern']);
});

it('EG8 orders equal groups by the fingerprint as text, also when PHP would read both fingerprints as numbers', function () {
    // "2331982e5342" is a float in exponent form and "993084351600" an integer: <=> compares them as numbers (the first
    // is larger), strcmp as text (the first is smaller). Added in the wrong order, so only the comparison sorts them.
    $fb = ErrorFingerprint::of('error', 'Cause fb');
    $nh = ErrorFingerprint::of('error', 'Cause nh');
    $groups = errorGroups();
    $groups->add(errorEntry('2026-10-02 09:00:00', 'error', 'Cause nh'), errorSource(), 'app.log');
    $groups->add(errorEntry('2026-10-02 09:00:00', 'error', 'Cause fb'), errorSource(), 'app.log');

    expect([$fb, $nh])->toBe(['2331982e5342', '993084351600'])
        ->and(array_column($groups->top(10), 'fingerprint'))->toBe([$fb, $nh]);
});
