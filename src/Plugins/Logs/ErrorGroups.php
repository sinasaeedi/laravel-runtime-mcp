<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins\Logs;

use Carbon\CarbonImmutable;
use SinaSaeedi\RuntimeMcp\Security\Redaction\Redactor;
use SinaSaeedi\RuntimeMcp\Support\Timestamp;

/**
 * 01-26 Part B: the error-level entries of one scan, grouped by ErrorFingerprint. One instance per call (rule 14).
 * Keeps per group only the count, the first and last time and one sample (the newest entry), never every entry.
 * Also counts every entry it sees, and the oldest time read per source, for the cut note.
 */
final class ErrorGroups
{
    /** The pattern in the result is cut to this many characters (the fingerprint uses ErrorFingerprint::MAX_PATTERN). */
    public const SHOWN_PATTERN = 300;

    /**
     * Fingerprint => group. A 12-digit fingerprint becomes an int key, so top() reads only the values.
     *
     * @var array<array-key, array{fingerprint: string, level: string, pattern: string, count: int, first: CarbonImmutable, last: CarbonImmutable, sample: array<string, mixed>}>
     */
    private array $groups = [];

    /** @var array<string, CarbonImmutable> source channel => the oldest entry time read from it */
    private array $oldest = [];

    private int $read = 0;

    public function __construct(private readonly Redactor $redactor) {}

    /** For LogScan's keep closure: every entry of the window comes here, newest first. */
    public function add(LogEntry $entry, LogSource $source, string $file): void
    {
        $this->read++;
        $oldest = $this->oldest[$source->channel] ?? null;

        if ($oldest === null || $entry->time < $oldest) {
            $this->oldest[$source->channel] = $entry->time;
        }

        if (LogEntryParser::rank($entry->level) < LogEntryParser::rank('error')) {
            return;
        }

        $pattern = ErrorFingerprint::pattern($this->redactor->redactString($entry->message));
        $fingerprint = ErrorFingerprint::of($entry->level, $pattern);
        $group = $this->groups[$fingerprint] ?? null;

        if ($group === null) {
            $this->groups[$fingerprint] = [
                'fingerprint' => $fingerprint,
                'level' => $entry->level,
                'pattern' => $pattern,
                'count' => 1,
                'first' => $entry->time,
                'last' => $entry->time,
                'sample' => $this->sample($entry, $source, $file),
            ];

            return;
        }

        $group['count']++;

        if ($entry->time < $group['first']) {
            $group['first'] = $entry->time;
        }

        // The scan is newest first, so this is rare (two lines of one file out of order): the sample stays the newest.
        if ($entry->time > $group['last']) {
            $group['last'] = $entry->time;
            $group['sample'] = $this->sample($entry, $source, $file);
        }

        $this->groups[$fingerprint] = $group;
    }

    /** The number of groups. */
    public function count(): int
    {
        return count($this->groups);
    }

    /** The entries seen, at every level. */
    public function read(): int
    {
        return $this->read;
    }

    /**
     * The time before which errors may be missing after a cut: the newest of the per-source oldest times, since in a
     * stack another source may have been read further back. Null when a source gave no entry (it may not have been
     * read at all, so no time is true for it).
     */
    public function cutTime(int $sources): ?CarbonImmutable
    {
        if ($sources < 1 || count($this->oldest) < $sources) {
            return null;
        }

        return max($this->oldest);
    }

    /**
     * The groups by count, then by last seen, then by fingerprint (one order for equal groups), at most $limit.
     *
     * @return list<array{fingerprint: string, level: string, pattern: string, count: int, first_seen: string, last_seen: string, sample: array<string, mixed>}>
     */
    public function top(int $limit): array
    {
        $groups = array_values($this->groups);

        // strcmp for the fingerprint: <=> compares two numeric strings ("2331982e5342", "993084351600") as numbers.
        usort($groups, fn (array $a, array $b): int => ([$b['count'], $b['last']] <=> [$a['count'], $a['last']]) ?: strcmp($a['fingerprint'], $b['fingerprint']));

        return array_map(fn (array $group): array => [
            'fingerprint' => $group['fingerprint'],
            'level' => $group['level'],
            'pattern' => mb_substr($group['pattern'], 0, self::SHOWN_PATTERN),
            'count' => $group['count'],
            'first_seen' => (string) Timestamp::iso($group['first']),
            'last_seen' => (string) Timestamp::iso($group['last']),
            'sample' => $group['sample'],
        ], array_slice($groups, 0, $limit));
    }

    /**
     * The entry as logs_search shows it: redacted whole, then the context cut (01-25). Built only for a new group or a
     * newer entry, never for every entry: RedactedEntry redacts the whole context.
     *
     * @return array<string, mixed>
     */
    private function sample(LogEntry $entry, LogSource $source, string $file): array
    {
        return RedactedEntry::of($entry, $this->redactor)->toArray($source->channel, $file);
    }
}
