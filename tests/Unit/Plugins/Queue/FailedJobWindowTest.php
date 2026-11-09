<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\FailedJobWindow;

/*
 * 01-23 Part B and C: the from/to window, in the app timezone. Each test sets PHP's default timezone (Laravel sets
 * it from app.timezone) and restores it.
 */

beforeEach(fn () => date_default_timezone_set('Europe/London'));
afterEach(fn () => date_default_timezone_set('UTC'));

/** @param  array<string, string>  $data */
function failedJobWindowPasses(array $data): bool
{
    $validator = (new Factory(new Translator(new ArrayLoader, 'en')))->make($data, [
        'from' => ['filled', 'string', 'max:64', 'date'],
        'to' => ['filled', 'string', 'max:64', 'date', new FailedJobWindow],
    ]);

    return $validator->passes();
}

/**
 * @param  array{CarbonImmutable, CarbonImmutable}  $window
 * @return array{string, string}
 */
function failedJobWindowIso(array $window): array
{
    return [$window[0]->toIso8601String(), $window[1]->toIso8601String()];
}

it('FW1 reads a date-only from as the start of the day and a date-only to as its last second', function () {
    expect(FailedJobWindow::bound('2026-10-01', end: false)->toIso8601String())->toBe('2026-10-01T00:00:00+01:00')
        ->and(FailedJobWindow::bound('2026-10-01', end: true)->toIso8601String())->toBe('2026-10-01T23:59:59+01:00')
        ->and(FailedJobWindow::bound('2026-10-01', end: true)->micro)->toBe(0)
        ->and(FailedJobWindow::bound('2026-12-01', end: false)->toIso8601String())->toBe('2026-12-01T00:00:00+00:00');
});

it('FW2 reads a date-time with an offset as that instant, and one without in the app timezone', function () {
    expect(FailedJobWindow::bound('2026-10-01T09:00:00Z', end: false)->toIso8601String())->toBe('2026-10-01T10:00:00+01:00')
        ->and(FailedJobWindow::bound('2026-10-01T09:00:00-04:00', end: true)->toIso8601String())->toBe('2026-10-01T14:00:00+01:00')
        ->and(FailedJobWindow::bound('2026-10-01T09:00:00', end: false)->toIso8601String())->toBe('2026-10-01T09:00:00+01:00')
        ->and(FailedJobWindow::binding(FailedJobWindow::bound('2026-10-01T09:00:00Z', end: false)))->toBe('2026-10-01 10:00:00');
});

it('FW3 gives the summary the last 24 hours by default, from to now, or the 24 hours before to', function () {
    $now = CarbonImmutable::parse('2026-10-02T09:30:15.250000Z');
    $iso = failedJobWindowIso(...);

    expect($iso(FailedJobWindow::summary(null, null, $now)))->toBe(['2026-10-01T10:30:15+01:00', '2026-10-02T10:30:15+01:00'])
        ->and($iso(FailedJobWindow::summary('2026-10-01', null, $now)))->toBe(['2026-10-01T00:00:00+01:00', '2026-10-02T10:30:15+01:00'])
        ->and($iso(FailedJobWindow::summary(null, '2026-10-01', $now)))->toBe(['2026-09-30T23:59:59+01:00', '2026-10-01T23:59:59+01:00'])
        ->and($iso(FailedJobWindow::summary('2026-09-01', '2026-09-30', $now)))->toBe(['2026-09-01T00:00:00+01:00', '2026-09-30T23:59:59+01:00']);
});

it('FW4 refuses a to before from by the window reading, and leaves a bad date to the date rule', function () {
    expect(failedJobWindowPasses(['from' => '2026-10-01T12:00:00', 'to' => '2026-10-01']))->toBeTrue()
        ->and(failedJobWindowPasses(['from' => '2026-10-01', 'to' => '2026-10-01']))->toBeTrue()
        ->and(failedJobWindowPasses(['from' => '2026-10-02', 'to' => '2026-10-01T23:59:59']))->toBeFalse()
        ->and(failedJobWindowPasses(['from' => '2026-10-01T10:00:00+02:00', 'to' => '2026-10-01T09:30:00+01:00']))->toBeTrue()
        ->and(failedJobWindowPasses(['from' => '2026-10-01T10:00:00+00:00', 'to' => '2026-10-01T09:30:00+00:00']))->toBeFalse()
        ->and(failedJobWindowPasses(['to' => '2026-10-01']))->toBeTrue()
        ->and(failedJobWindowPasses(['from' => 'tomorrow', 'to' => '2026-10-01']))->toBeFalse()
        ->and(failedJobWindowPasses(['from' => '2026-02-30']))->toBeFalse();

    $messages = (new Factory(new Translator(new ArrayLoader, 'en')))
        ->make(['from' => '2026-10-02', 'to' => '2026-10-01'], ['to' => [new FailedJobWindow]])
        ->errors()->all();

    expect($messages)->toBe([FailedJobWindow::TO_BEFORE_FROM]);
});
