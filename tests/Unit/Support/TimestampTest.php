<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use SinaSaeedi\RuntimeMcp\Support\Timestamp;

/*
 * Spec 4.4: ISO 8601 with an offset, in the app timezone. Laravel sets PHP's default timezone from
 * app.timezone at boot, so each test runs in Europe/Amsterdam (+02:00 in summer, +01:00 in winter)
 * and puts the default back.
 */

function timestampInAmsterdam(DateTimeInterface|string|int|null $value): ?string
{
    $previous = date_default_timezone_get();
    date_default_timezone_set('Europe/Amsterdam');

    try {
        return Timestamp::iso($value);
    } finally {
        date_default_timezone_set($previous);
    }
}

it('gives null for null', function () {
    expect(timestampInAmsterdam(null))->toBeNull();
});

it('converts a DateTime in another zone to the app zone, with the offset', function () {
    $date = new DateTimeImmutable('2026-10-02 12:03:11', new DateTimeZone('UTC'));

    expect(timestampInAmsterdam($date))->toBe('2026-10-02T14:03:11+02:00');
});

it('converts a Carbon date without changing it', function () {
    $date = Carbon::parse('2026-01-15 08:00:00', 'UTC');

    expect(timestampInAmsterdam($date))->toBe('2026-01-15T09:00:00+01:00')
        ->and($date->getTimezone()->getName())->toBe('UTC');
});

it('reads a string without an offset in the app zone', function () {
    expect(timestampInAmsterdam('2026-10-02 14:03:11'))->toBe('2026-10-02T14:03:11+02:00');
});

it('converts a string with an offset to the app zone', function () {
    expect(timestampInAmsterdam('2026-10-02T12:03:11+00:00'))->toBe('2026-10-02T14:03:11+02:00');
});

it('reads an int as a Unix time in seconds', function () {
    expect(timestampInAmsterdam(1_790_000_000))->toBe('2026-09-21T16:13:20+02:00');
});
