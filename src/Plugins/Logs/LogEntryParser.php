<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins\Logs;

use Carbon\CarbonImmutable;
use Generator;
use Throwable;

/**
 * Spec 6.9, 01-25 Part C: entries from lines that come newest first. A header line starts an entry; every line under
 * it, up to the next header, belongs to it (stack traces, line breaks inside a message). Reading backwards, the lines
 * are collected until a header closes the entry. Lines before the oldest header read are dropped: they belong to an
 * older entry that the read did not reach.
 *
 * Laravel's line format is LogManager::formatter(): new LineFormatter(null, 'Y-m-d H:i:s', true, true, true), so
 * "[%datetime%] %channel%.%level_name%: %message% %context% %extra%" with real line breaks inside a trace.
 */
final class LogEntryParser
{
    /** Monolog's level names, lowest first. */
    public const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    public const HEADER = '/^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:[+-]\d{2}:?\d{2}|Z)?)\] (\S+)\.([A-Z]+):(?: (.*))?$/sD';

    /** Where the context or extra object starts on a header line. */
    private const CONTEXT_START = ' {"';

    /** The place of a level in LEVELS (debug 0, emergency 7), or -1 for another name. */
    public static function rank(string $level): int
    {
        $rank = array_search($level, self::LEVELS, true);

        return is_int($rank) ? $rank : -1;
    }

    /**
     * @param  iterable<string>  $lines  newest first
     * @param  LogSource::LINE|LogSource::JSON  $format
     * @return Generator<int, LogEntry, mixed, int> the entries, newest first; returns the number of header lines
     */
    public function entries(iterable $lines, string $format): Generator
    {
        $pending = [];
        $headers = 0;

        foreach ($lines as $line) {
            $entry = $format === LogSource::JSON ? self::json($line, $pending) : self::line($line, $pending);

            if ($entry === null) {
                $pending[] = $line;

                continue;
            }

            $headers++;
            $pending = [];

            yield $entry;
        }

        return $headers;
    }

    /**
     * A header line of the line format, with the lines under it (newest first), or null.
     *
     * @param  list<string>  $pending
     */
    public static function line(string $line, array $pending): ?LogEntry
    {
        if (preg_match(self::HEADER, $line, $m) !== 1) {
            return null;
        }

        $time = self::time($m[1]);
        $level = strtolower($m[3]);

        if ($time === null || ! in_array($level, self::LEVELS, true)) {
            return null;
        }

        $rest = $m[4] ?? '';
        $start = strpos($rest, self::CONTEXT_START);
        $message = self::withoutEmpty($start === false ? $rest : substr($rest, 0, $start));
        $parts = $start === false ? [] : [substr($rest, $start + 1)];

        foreach (array_reverse($pending) as $continuation) {
            $parts[] = $continuation;
        }

        $context = self::withoutEmpty(implode("\n", $parts));

        return new LogEntry($time, $level, $m[2], $message, $context === '' ? null : $context);
    }

    /**
     * Without trailing spaces, and without the " []" that Monolog's own LineFormatter writes for an empty context and an
     * empty extra (`formatter => 'default'` keeps that formatter; Laravel's leaves them out).
     */
    private static function withoutEmpty(string $text): string
    {
        return (string) preg_replace('/(?: \[\]){1,2}$/D', '', rtrim($text));
    }

    /**
     * A record of Monolog's JsonFormatter: an object with a string datetime, level_name and message. Lines under it
     * that are no record are kept as text after the record's context.
     *
     * @param  list<string>  $pending
     */
    public static function json(string $line, array $pending): ?LogEntry
    {
        if (! str_starts_with($line, '{')) {
            return null;
        }

        $record = json_decode($line, true);

        if (! is_array($record) || ! is_string($record['datetime'] ?? null) || ! is_string($record['level_name'] ?? null) || ! is_string($record['message'] ?? null)) {
            return null;
        }

        $time = self::time($record['datetime']);
        $level = strtolower($record['level_name']);

        if ($time === null || ! in_array($level, self::LEVELS, true)) {
            return null;
        }

        $context = ['context' => $record['context'] ?? [], 'extra' => $record['extra'] ?? []];

        if ($pending !== []) {
            $text = (string) json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            $context = $text."\n".implode("\n", array_reverse($pending));
        }

        $env = is_string($record['channel'] ?? null) ? $record['channel'] : '';

        return new LogEntry($time, $level, $env, $record['message'], $context);
    }

    /**
     * A stamp in the app timezone unless it carries an offset. A stamp that does not read back the same (2026-02-30,
     * a time in a DST gap) is no header: Laravel never writes one (01-20's rule for values that do not read back).
     */
    private static function time(string $stamp): ?CarbonImmutable
    {
        try {
            $time = CarbonImmutable::parse($stamp, date_default_timezone_get());
        } catch (Throwable) {
            return null;
        }

        if ($time->format('Y-m-d H:i:s') !== str_replace('T', ' ', substr($stamp, 0, 19))) {
            return null;
        }

        // Kept in the stamp's own offset: times compare as instants, and Timestamp::iso() shows the app timezone.
        return $time;
    }
}
