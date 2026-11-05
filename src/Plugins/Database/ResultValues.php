<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins\Database;

use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Carbon;
use SinaSaeedi\RuntimeMcp\Security\Sql\QueryResult;

/**
 * Spec 4.4, 01-20: the db_query values that depend on the driver, made the same on every server.
 * QueryRunner (01-15) keeps every value as PDO gives it; this class changes two kinds, by the column's
 * native_type (measured on PostgreSQL 17, MySQL 8.4, MariaDB 11.4 and 10.11):
 *
 * - A timestamp becomes ISO 8601 with an offset, in the app timezone, as Timestamp::iso() (01-08) gives
 *   it, but with the fraction of a second kept (Sina, 2026-11-04, Q3). A naive value is read in the app
 *   timezone, as Laravel reads it. A value that does not parse, or does not read back the same (a MySQL
 *   zero date, an invalid date, a time in a DST gap), stays as it is: never invent a time.
 * - A PostgreSQL float4 or float8 that PDO gives as a numeric string (PHP 8.2 and 8.3) becomes a float,
 *   as PHP 8.4 gives it (Sina, 2026-10-24). 'NaN', 'Infinity' and '-Infinity' stay strings (01-15 Q3).
 *
 * Every other value, and every value of a column with no native_type, stays as it is: numeric and
 * DECIMAL stay strings (money), date and time columns stay as the database wrote them.
 */
final class ResultValues
{
    /** PostgreSQL timestamptz carries its offset; the others are naive (01-20 audit, probe 1). */
    public const TIMESTAMPS = ['timestamptz', 'timestamp', 'DATETIME', 'TIMESTAMP'];

    public const FLOATS = ['float4', 'float8'];

    /** @return list<list<mixed>> the rows, in the same order and shape */
    public static function convert(QueryResult $result): array
    {
        $rows = [];

        foreach ($result->rows as $row) {
            $converted = [];

            foreach ($row as $i => $value) {
                $converted[] = self::value($result->types[$i] ?? null, $value);
            }

            $rows[] = $converted;
        }

        return $rows;
    }

    public static function value(?string $type, mixed $value): mixed
    {
        if (! is_string($value) || $type === null) {
            return $value;
        }

        if (in_array($type, self::FLOATS, true)) {
            return is_numeric($value) ? (float) $value : $value;
        }

        if (in_array($type, self::TIMESTAMPS, true)) {
            return self::timestamp($value) ?? $value;
        }

        return $value;
    }

    private static function timestamp(string $value): ?string
    {
        $timezone = date_default_timezone_get();

        try {
            // As Timestamp::iso(): an offset in the value wins, a naive value is read in the app timezone.
            $date = Carbon::parse($value, $timezone);
        } catch (InvalidFormatException) {
            // infinity, -infinity, a BC date, a five-digit year.
            return null;
        }

        // Carbon rolls '0000-00-00 00:00:00' to -0001-11-30 and '2026-02-30' to 2026-03-02.
        if ($date->format('Y-m-d H:i:s') !== substr($value, 0, 19)) {
            return null;
        }

        $date = $date->setTimezone($timezone);

        // A local mean time offset has seconds (London before 1847: -00:01:15), and ISO 8601 has none:
        // the "P" offset would move the instant.
        if ($date->getOffset() % 60 !== 0) {
            return null;
        }

        return $date->format($date->micro === 0 ? 'Y-m-d\TH:i:sP' : 'Y-m-d\TH:i:s.uP');
    }
}
