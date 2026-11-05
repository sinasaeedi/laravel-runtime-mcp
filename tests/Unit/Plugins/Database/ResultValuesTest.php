<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Plugins\Database\ResultValues;
use SinaSaeedi\RuntimeMcp\Security\Sql\LimitStrategy;
use SinaSaeedi\RuntimeMcp\Security\Sql\QueryResult;

/*
 * Spec 4.4, 01-20: timestamps in the app timezone with an offset and the fraction kept; PostgreSQL
 * float strings as floats; every other value as PDO gave it. The values are the forms measured through
 * PDO on PostgreSQL 17.11, MySQL 8.4.11 and MariaDB 11.4.13 (01-20 audit, probe 1). Laravel sets PHP's
 * default timezone from app.timezone at boot; these tests set it themselves (Europe/London: +01:00 in
 * summer, +00:00 in winter) and restore it in `finally`.
 */

/** resultValueInLondon() with the app timezone Europe/London. */
function resultValueInLondon(?string $type, mixed $value): mixed
{
    $previous = date_default_timezone_get();
    date_default_timezone_set('Europe/London');

    try {
        return ResultValues::value($type, $value);
    } finally {
        date_default_timezone_set($previous);
    }
}

it('RV1 gives a timestamp as ISO 8601 in the app timezone, with its fraction', function (string $type, string $value, string $iso) {
    expect(resultValueInLondon($type, $value))->toBe($iso);
})->with([
    'timestamptz, summer' => ['timestamptz', '2026-10-01 08:12:44+00', '2026-10-01T09:12:44+01:00'],
    'timestamptz, winter' => ['timestamptz', '2026-01-15 09:00:00+00', '2026-01-15T09:00:00+00:00'],
    'timestamptz, another offset' => ['timestamptz', '2026-10-01 13:42:44+05:30', '2026-10-01T09:12:44+01:00'],
    'timestamptz, a fraction (now())' => ['timestamptz', '2026-11-04 09:09:23.824712+00', '2026-11-04T09:09:23.824712+00:00'],
    'timestamptz, half a second' => ['timestamptz', '2026-10-01 08:12:44.5+00', '2026-10-01T09:12:44.500000+01:00'],
    'timestamp, naive' => ['timestamp', '2026-10-01 09:12:44', '2026-10-01T09:12:44+01:00'],
    'timestamp, a fraction' => ['timestamp', '2026-10-01 09:12:44.123456', '2026-10-01T09:12:44.123456+01:00'],
    'DATETIME' => ['DATETIME', '2026-10-01 09:12:44', '2026-10-01T09:12:44+01:00'],
    'DATETIME(6)' => ['DATETIME', '2026-10-01 09:12:44.123456', '2026-10-01T09:12:44.123456+01:00'],
    'DATETIME(6), a zero fraction' => ['DATETIME', '2026-10-01 09:12:44.000000', '2026-10-01T09:12:44+01:00'],
    'TIMESTAMP' => ['TIMESTAMP', '2026-01-15 09:00:00', '2026-01-15T09:00:00+00:00'],
]);

it('RV2 keeps a timestamp that does not parse or does not read back the same: never an invented time', function (string $type, string $value) {
    expect(resultValueInLondon($type, $value))->toBe($value);
})->with([
    'a MySQL zero date' => ['DATETIME', '0000-00-00 00:00:00'],
    'a MySQL zero TIMESTAMP' => ['TIMESTAMP', '0000-00-00 00:00:00'],
    'an invalid date (ALLOW_INVALID_DATES)' => ['DATETIME', '2026-02-30 00:00:00'],
    'a time in the DST gap' => ['DATETIME', '2026-03-29 01:30:00'],
    'infinity' => ['timestamptz', 'infinity'],
    '-infinity' => ['timestamp', '-infinity'],
    'a BC date' => ['timestamp', '0044-03-15 12:00:00 BC'],
    'a five-digit year' => ['timestamp', '10000-01-01 00:00:00'],
    'not a date' => ['timestamptz', 'soon'],
    'an instant in local mean time (-00:01:15)' => ['timestamptz', '1840-01-01 12:00:00+00'],
    'a naive time in local mean time' => ['timestamp', '1840-01-01 12:00:00'],
]);

it('RV3 keeps the values of every other type, also a date-like string', function (?string $type, mixed $value) {
    expect(resultValueInLondon($type, $value))->toBe($value);
})->with([
    'PostgreSQL date' => ['date', '2026-10-01'],
    'PostgreSQL time' => ['time', '09:12:44'],
    'PostgreSQL timetz' => ['timetz', '09:12:44+02'],
    'PostgreSQL interval' => ['interval', '1 day'],
    'PostgreSQL timestamptz[]' => ['_timestamptz', '{"2026-11-04 09:09:23.824712+00"}'],
    'PostgreSQL numeric (money)' => ['numeric', '1.50'],
    'PostgreSQL text' => ['text', '2026-10-01 09:12:44'],
    'MySQL DATE' => ['DATE', '2026-10-01'],
    'MySQL TIME' => ['TIME', '09:12:44'],
    'MySQL YEAR' => ['YEAR', '2026'],
    'MySQL DECIMAL' => ['NEWDECIMAL', '99.50'],
    'MySQL VARCHAR' => ['VAR_STRING', '2026-10-01 09:12:44'],
    'MySQL DOUBLE as a string' => ['DOUBLE', '1.5'],
    'no native type' => [null, '2026-10-01 09:12:44'],
    'no native type, a float string' => [null, '1.5'],
]);

it('RV4 makes a PostgreSQL float4 or float8 string a float, and keeps the non-finite strings', function (string $type, mixed $value, mixed $expected) {
    expect(resultValueInLondon($type, $value))->toBe($expected);
})->with([
    'float8 on PHP 8.2 and 8.3' => ['float8', '1.5', 1.5],
    'float4 on PHP 8.2 and 8.3' => ['float4', '1.5', 1.5],
    'a large float8' => ['float8', '1e+300', 1.0E+300],
    'a negative float8' => ['float8', '-2.25', -2.25],
    'a whole float8' => ['float8', '3', 3.0],
    'float8 on PHP 8.4' => ['float8', 1.5, 1.5],
    'NaN' => ['float8', 'NaN', 'NaN'],
    'Infinity' => ['float8', 'Infinity', 'Infinity'],
    '-Infinity' => ['float4', '-Infinity', '-Infinity'],
]);

it('RV5 keeps null and non-string values of a timestamp column', function (mixed $value) {
    expect(resultValueInLondon('timestamptz', $value))->toBe($value)
        ->and(resultValueInLondon('DATETIME', $value))->toBe($value);
})->with([
    'null' => [null],
    'an int' => [5],
    'a float' => [1.5],
    'a bool' => [true],
]);

it('RV6 converts each row by the type of its column, and keeps the shape', function () {
    $result = new QueryResult(
        ['id', 'created_at', 'total', 'ratio', 'note'],
        [
            [1, '2026-10-01 09:12:44', '99.50', '1.5', '2026-10-01 09:12:44'],
            [2, null, '0.00', 'NaN', null],
        ],
        2,
        false,
        LimitStrategy::Wrapped,
        false,
        ['int8', 'timestamp', 'numeric', 'float8', 'text'],
    );

    $previous = date_default_timezone_get();
    date_default_timezone_set('Europe/London');

    try {
        $rows = ResultValues::convert($result);
    } finally {
        date_default_timezone_set($previous);
    }

    expect($rows)->toBe([
        [1, '2026-10-01T09:12:44+01:00', '99.50', 1.5, '2026-10-01 09:12:44'],
        [2, null, '0.00', 'NaN', null],
    ]);
});

it('RV7 keeps every value when the result has no types', function () {
    $rows = [[1, '2026-10-01 09:12:44', '1.5']];

    expect(ResultValues::convert(new QueryResult(['a', 'b', 'c'], $rows, 1, false, LimitStrategy::Wrapped, false)))->toBe($rows)
        ->and(ResultValues::convert(new QueryResult(['a', 'b', 'c'], $rows, 1, false, LimitStrategy::Wrapped, false, ['int8'])))->toBe($rows);
});
