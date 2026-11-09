<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins\Queue;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use SinaSaeedi\RuntimeMcp\Support\Timestamp;
use Throwable;

/**
 * 01-23 Part B and C: the from/to window of the failed-job tools, in the app timezone (Laravel writes failed_at
 * as Date::now(), so the stored value is a time in the app timezone).
 *
 * A value with no offset is read in the app timezone. A date-only `from` is the start of that day, a date-only
 * `to` is its last second. Both bounds are inclusive. As a rule on `to`, it refuses a `to` before `from` by the
 * same reading: Laravel's after_or_equal reads a date-only `to` as midnight, so it would refuse
 * from=2026-10-01T12:00:00 to=2026-10-01, a window this tool accepts.
 */
final class FailedJobWindow implements DataAwareRule, ValidationRule
{
    /** What a bound looks like in a binding: the format Laravel writes failed_at in. */
    public const BINDING = 'Y-m-d H:i:s';

    public const SUMMARY_HOURS = 24;

    public const TO_BEFORE_FROM = 'The to field must be the same as or after from.';

    /** @var array<array-key, mixed> */
    private array $data = [];

    /** @param  array<array-key, mixed>  $data */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $from = $this->data['from'] ?? null;

        // The date rule reports a value that is not a date; this rule then has nothing to compare.
        if (! is_string($from) || ! is_string($value)) {
            return;
        }

        try {
            $before = self::bound($value, end: true) < self::bound($from, end: false);
        } catch (Throwable) {
            return;
        }

        if ($before) {
            $fail(self::TO_BEFORE_FROM);
        }
    }

    /** A validated from or to value in the app timezone. A date-only end is the last second of that day. */
    public static function bound(string $value, bool $end): CarbonImmutable
    {
        $timezone = date_default_timezone_get();

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1) {
            $day = CarbonImmutable::parse($value, $timezone);

            return $end ? $day->endOfDay()->startOfSecond() : $day->startOfDay();
        }

        return CarbonImmutable::parse($value, $timezone)->setTimezone($timezone);
    }

    /**
     * The summary's window: no input is the last 24 hours, ending now; only from runs to now; only to starts
     * 24 hours before it.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    public static function summary(?string $from, ?string $to, CarbonImmutable $now): array
    {
        $end = $to === null ? $now->setTimezone(date_default_timezone_get())->startOfSecond() : self::bound($to, end: true);
        $start = $from === null ? $end->subHours(self::SUMMARY_HOURS) : self::bound($from, end: false);

        return [$start, $end];
    }

    public static function binding(CarbonImmutable $bound): string
    {
        return $bound->setTimezone(date_default_timezone_get())->format(self::BINDING);
    }

    /** ISO 8601 with an offset, as every timestamp in a result (spec 4.4). */
    public static function iso(CarbonImmutable $bound): string
    {
        return (string) Timestamp::iso($bound);
    }
}
