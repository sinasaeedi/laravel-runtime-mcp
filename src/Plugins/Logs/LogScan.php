<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins\Logs;

use Carbon\CarbonImmutable;
use Closure;
use Generator;

/**
 * 01-25 Part D: reads the files of a resolved channel, newest entry first, within one Budget. Each source (a single or
 * daily channel) is one stream of entries over its files; the streams are merged by time, so a stack of two channels
 * gives one list in time order (one source after the other would fill `limit` from the first and hide newer entries of
 * the second). A file reached by two channels is read once. 01-26 reuses this with its own keep closure.
 */
final class LogScan
{
    /** %1$s the channel ("Channel `daily`"), %2$s the first day, %3$s the last day, %4$s the name pattern. */
    public const NO_DAILY_FILE = '%1$s has no file for %2$s to %3$s (%4$s).';

    /** %1$s the channel, %2$s the last day, %3$s the name pattern. */
    public const NO_DAILY_FILE_UP_TO = '%1$s has no file up to %2$s (%3$s).';

    public const NO_ENTRIES = "File %s has no entry in Laravel's line format or the JSON format.";

    /** A file cut by the budget with no header in the part read: %1$s the file, %2$d the bytes read from it. */
    public const NO_ENTRIES_IN = "File %1\$s has no entry in Laravel's line format or the JSON format in its last %2\$d bytes.";

    public function __construct(
        private readonly ReverseLineReader $reader = new ReverseLineReader,
        private readonly LogEntryParser $parser = new LogEntryParser,
    ) {}

    /**
     * @param  CarbonImmutable|null  $from  entries before it end their source (its older files are not read)
     * @param  CarbonImmutable|null  $to  entries after it are skipped; daily files after its day are not read (default: now)
     * @param  Closure(LogEntry, LogSource, string): (array<string, mixed>|null)  $keep  the row for a matching entry, or null
     * @param  int  $want  stop when this many rows are kept
     */
    public function run(ResolvedChannel $resolved, ?CarbonImmutable $from, ?CarbonImmutable $to, Budget $budget, Closure $keep, int $want): ScanResult
    {
        $skipped = $resolved->skipped;
        $read = [];
        $cut = false;
        $seen = [];
        $streams = [];

        foreach ($resolved->sources as $source) {
            $files = [];

            foreach ($source->daily ? $this->dailyFiles($source, $from, $to, $skipped) : [$source->path] as $path) {
                $key = realpath($path);
                $key = $key === false ? $path : $key;

                if (! isset($seen[$key])) {
                    $seen[$key] = true;
                    $files[] = $path;
                }
            }

            if ($files !== []) {
                $streams[] = [$source, $this->stream($source, $files, $from, $budget, $read, $skipped, $cut)];
            }
        }

        $kept = [];

        while (count($kept) < $want) {
            $next = null;

            foreach ($streams as $index => [, $stream]) {
                if (! $stream->valid()) {
                    continue;
                }

                if ($next === null || $stream->current()[0]->time > $streams[$next][1]->current()[0]->time) {
                    $next = $index;
                }
            }

            if ($next === null) {
                break;
            }

            [$source, $stream] = $streams[$next];
            [$entry, $file] = $stream->current();
            $stream->next();

            if ($to !== null && $entry->time > $to) {
                continue;
            }

            $row = $keep($entry, $source, $file);

            if ($row !== null) {
                $kept[] = $row;
            }
        }

        return new ScanResult($kept, array_reverse(array_values($read)), $cut, $skipped, $budget->used());
    }

    /**
     * @param  list<string>  $skipped
     * @return list<string>
     */
    private function dailyFiles(LogSource $source, ?CarbonImmutable $from, ?CarbonImmutable $to, array &$skipped): array
    {
        $last = $to ?? CarbonImmutable::now();
        $files = DailyFiles::list($source, $from, $last);

        if ($files === []) {
            $timezone = date_default_timezone_get();
            $shown = LogChannelResolver::shown($source->channel);
            $lastDay = $last->setTimezone($timezone)->format('Y-m-d');
            $skipped[] = $from === null
                ? sprintf(self::NO_DAILY_FILE_UP_TO, $shown, $lastDay, DailyFiles::pattern($source))
                : sprintf(self::NO_DAILY_FILE, $shown, $from->setTimezone($timezone)->format('Y-m-d'), $lastDay, DailyFiles::pattern($source));
        }

        return $files;
    }

    /**
     * The entries of one source, newest first, over its files (newest first). Records each file it read, each file it
     * could not read, and a budget cut.
     *
     * @param  list<string>  $files
     * @param  array<string, string>  $read  path => base name, in the order the files were opened
     * @param  list<string>  $skipped
     * @return Generator<int, array{LogEntry, string}>
     */
    private function stream(LogSource $source, array $files, ?CarbonImmutable $from, Budget $budget, array &$read, array &$skipped, bool &$cut): Generator
    {
        foreach ($files as $path) {
            $name = basename($path);
            $problem = ReverseLineReader::problem($path);

            if ($problem !== null) {
                $skipped[] = $problem;

                continue;
            }

            if ($budget->spent()) {
                $cut = true;

                return;
            }

            $before = $budget->used();
            $lines = $this->reader->lines($path, $budget);
            $entries = $this->parser->entries($lines, $source->format);
            $read[$path] = $name;

            foreach ($entries as $entry) {
                if ($from !== null && $entry->time < $from) {
                    return;
                }

                yield [$entry, $name];
            }

            $end = $lines->getReturn();

            if ($end === ReadEnd::Budget) {
                $cut = true;

                // A file in another format is mostly larger than the budget: no header in the part read is no search.
                if ($entries->getReturn() === 0 && $budget->used() > $before) {
                    unset($read[$path]);
                    $skipped[] = sprintf(self::NO_ENTRIES_IN, $name, $budget->used() - $before);
                }

                return;
            }

            $problem = match (true) {
                $end === ReadEnd::Changed => sprintf(ReverseLineReader::CHANGED, $name),
                $end === ReadEnd::Unreadable => sprintf(ReverseLineReader::UNREADABLE, $name),
                $entries->getReturn() === 0 && $budget->used() > $before => sprintf(self::NO_ENTRIES, $name),
                default => null,
            };

            if ($problem !== null) {
                unset($read[$path]);
                $skipped[] = $problem;
            }
        }
    }
}
