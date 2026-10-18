<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Spec 4.4: every timestamp in a result is ISO 8601 with an offset (2026-10-02T14:03:11+01:00), in
 * the app timezone (Laravel sets PHP's default timezone from app.timezone). Every tool uses this.
 */
final class Timestamp
{
    /**
     * A string without an offset is read in the app timezone. An int is a Unix time in seconds.
     *
     * @throws \Carbon\Exceptions\InvalidFormatException for a string that is not a date
     */
    public static function iso(DateTimeInterface|string|int|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $timezone = date_default_timezone_get();

        $date = match (true) {
            $value instanceof DateTimeInterface => Carbon::instance($value),
            is_int($value) => Carbon::createFromTimestamp($value),
            default => Carbon::parse($value, $timezone),
        };

        return $date->setTimezone($timezone)->toIso8601String();
    }
}
