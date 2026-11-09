<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins\Queue;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\Failed\FileFailedJobProvider;
use SinaSaeedi\RuntimeMcp\Security\Grants\DeniedTables;
use SinaSaeedi\RuntimeMcp\Security\Grants\InvalidGrantConfig;
use SinaSaeedi\RuntimeMcp\Security\Grants\SchemaSnapshot;
use SinaSaeedi\RuntimeMcp\Security\Redaction\Redactor;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\QueryFailed;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\QueryPermissionDenied;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\QueryRejected;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\QueryTimedOut;
use SinaSaeedi\RuntimeMcp\Security\Sql\ReadOnlySession;
use SinaSaeedi\RuntimeMcp\Security\UnsupportedDriver;
use SinaSaeedi\RuntimeMcp\Support\Timestamp;
use Throwable;

/**
 * Spec 4.2, 4.3, 6.6, 6.8, 01-23 Part A: the failed jobs, read with bounded work. Never FailedJobProvider::all() on a
 * table: it loads every row with its payload and full stack trace (rule 8).
 *
 * A table is read on the read-only connection inside ReadOnlySession::run() (01-14: the read-only transaction, the
 * statement timeout, the session reset), as the read-only role: `failed_jobs` stays readable for these tools (Sina,
 * 2026-10-02). Only the projections leave the database: the job class from the payload's displayName, the first
 * line of the exception (at most RAW_LINE_CHARACTERS), and its class. The payload and the trace never reach PHP.
 *
 * The first line is redacted here, then cut to LINE_CHARACTERS (RunTool::cut()'s rule: a secret cut in half matches
 * no pattern). Every "could not look" is a FailedJobsUnavailable with package text (rule 11); a database message
 * never reaches a note, because it can name a host or a user (DbSchema's rule).
 *
 * Built through the container on every request; the constructor reads nothing.
 */
final class FailedJobReader
{
    public const LINE_CHARACTERS = 300;

    /** What SQL selects of the first line: room for a secret that starts before LINE_CHARACTERS (redact, then cut). */
    public const RAW_LINE_CHARACTERS = 2000;

    /** A job class or an exception class longer than this is cut. */
    public const NAME_CHARACTERS = 255;

    /** The most groups the summary shows per list. */
    public const GROUPS = 50;

    /** The largest failed-job file read (spec 4.3: file reads are bounded by bytes). A constant, as 01-17's limits. */
    public const MAX_FILE_BYTES = 10_000_000;

    /** As DbSchema::NOT_READABLE: on MySQL and MariaDB a missing table is also a permission error, so never "exists". */
    public const NOT_READABLE = 'Table `%s` does not exist, or the read-only role cannot read it.';

    public const DENIED = 'Table `%s` is in runtime-mcp.database.denied_tables, so the failed jobs are not read.';

    public const BAD_DENIED_TABLES = 'runtime-mcp.database.denied_tables is not a list of names, so the failed jobs are not read. Fix the config.';

    public const NO_UUID = 'Table `%s` has no `uuid` column, but queue.failed.driver is database-uuids.';

    public const INVALID_PAYLOAD = 'A payload in table `%s` is not valid JSON, so the job classes cannot be read.';

    public const TIMED_OUT = 'Reading table `%s` was stopped by the %d ms statement timeout. Narrow the window with from and to.';

    public const UNSUPPORTED = 'The failed-job tools support PostgreSQL, MySQL and MariaDB; the read-only connection uses %s.';

    public const REFUSED = 'The database refused the failed-job read (SQLSTATE %s).';

    public const PAYLOAD_HIDDEN = 'The read-only role cannot read `%s.payload`, so job_class is not shown.';

    public const PAYLOAD_FILTER = 'The read-only role cannot read `%s.payload`, so the job_class filter cannot be used.';

    public const REBOUND = 'queue.failer is not Laravel\'s file provider, so the failed jobs cannot be read.';

    public const FILE_UNREADABLE = 'The failed-job file is not readable by the PHP process.';

    public const FILE_TOO_LARGE = 'The failed-job file is %d bytes; v1.0.0 reads at most %d. Lower queue.failed.limit, or flush old failed jobs.';

    public const FILE_FORMAT = 'The failed-job file is not in the format Laravel writes, so it cannot be read.';

    /** Laravel's write() is a plain file_put_contents(), and read() takes no lock: a read can see an empty or changing file. */
    public const FILE_BUSY = 'The failed-job file was empty or changed while it was read; a worker may be writing it. Try again.';

    /** PostgreSQL: undefined table; MySQL and MariaDB (driver code): table does not exist. */
    private const MISSING_TABLE = ['42P01', 1146];

    /** PostgreSQL: undefined column; MySQL and MariaDB: unknown column. */
    private const MISSING_COLUMN = ['42703', 1054];

    /** PostgreSQL: invalid json text, a scalar where an object is needed; MySQL: invalid JSON text (MariaDB gives NULL). */
    private const BAD_JSON = ['22P02', '22023', 3141];

    public function __construct(
        private readonly Repository $config,
        private readonly ReadOnlySession $session,
        private readonly Application $app,
        private readonly ExceptionHandler $exceptions,
    ) {}

    public function source(): FailedJobSource
    {
        return FailedJobSource::fromConfig($this->config);
    }

    /** Where the tools looked, for a note: "in table `failed_jobs`" or "in the failed-job file". */
    public static function place(FailedJobSource $source): string
    {
        return $source->kind === FailedJobSource::TABLE ? 'in table `'.$source->table.'`' : 'in the failed-job file';
    }

    /**
     * The newest jobs that match, at most $limit, newest first (ties: the newest row first).
     *
     * @return array{jobs: list<array{id: string, job_class: string|null, queue: string|null, connection: string|null, failed_at: string|null, exception: string}>, more: bool, payload_hidden: bool}
     *
     * @throws FailedJobsUnavailable
     */
    public function list(FailedJobSource $source, FailedJobFilter $filter, int $limit): array
    {
        if ($source->kind === FailedJobSource::FILE) {
            $jobs = array_values(array_filter($this->fileJobs($source), fn (array $job): bool => self::matches($job, $filter)));
            $rows = array_slice($jobs, 0, $limit + 1);
            $payloadHidden = false;
        } else {
            [$rows, $payloadHidden] = $this->fromTable($source, $filter->jobClass !== null, function (Connection $ro, array $sql) use ($source, $filter, $limit): array {
                $g = $ro->getQueryGrammar();
                [$where, $bindings] = self::where($ro, $sql, $filter);
                $table = $g->wrapTable((string) $source->table);
                $order = ' order by '.$g->wrap('failed_at').' desc, '.$g->wrap('id').' desc';

                // The page's keys first, then the projections of those rows only. MySQL computes a select list for
                // every row before it sorts: with the projections in one query, 10,000 rows of 20 KB traces took
                // 4.3 s on MySQL 8.4 (0.006 s like this). The ID under its own alias: a select alias named like a
                // column wins in ORDER BY, so "uuid as id" would order the ties by uuid; id keeps the insert order.
                // The join names the table as FROM does: wrap('schema.table.id') would put the prefix on the schema.
                return self::select($ro, 'select '.$g->wrap((string) $source->idColumn).' as failed_job_id, '
                    .implode(', ', array_map($g->wrap(...), ['connection', 'queue', 'failed_at']))
                    .", {$sql['job_class']} as job_class, {$sql['first_line']} as first_line from {$table}"
                    .' join (select '.$g->wrap('id')." as page_id from {$table}{$where}{$order} limit ".($limit + 1).') page'
                    .' on page.page_id = '.$table.'.'.$g->wrap('id').$order, $bindings);
            });
        }

        $redactor = $this->app->make(Redactor::class);
        $jobs = [];

        foreach (array_slice($rows, 0, $limit) as $row) {
            $jobs[] = [
                'id' => self::text($row['failed_job_id'] ?? $row['id'] ?? null) ?? '',
                'job_class' => self::text($row['job_class'] ?? null),
                'queue' => self::text($row['queue'] ?? null),
                'connection' => self::text($row['connection'] ?? null),
                'failed_at' => self::iso($row['failed_at'] ?? null),
                'exception' => self::shortLine($redactor, self::text($row['first_line'] ?? null) ?? ''),
            ];
        }

        return ['jobs' => $jobs, 'more' => count($rows) > $limit, 'payload_hidden' => $payloadHidden];
    }

    /**
     * Counts in the window, grouped by job class and by exception class, the GROUPS largest of each.
     *
     * @return array{total: int, by_job_class: list<array{job_class: string|null, count: int}>, by_exception_class: list<array{exception_class: string|null, count: int}>, more_job_classes: bool, more_exception_classes: bool, payload_hidden: bool}
     *
     * @throws FailedJobsUnavailable
     */
    public function summary(FailedJobSource $source, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($source->kind === FailedJobSource::FILE) {
            $jobs = array_filter($this->fileJobs($source), fn (array $job): bool => self::matches($job, new FailedJobFilter(from: $from, to: $to)));
            [$total, $byJobClass, $byExceptionClass] = [count($jobs), self::groups($jobs, 'job_class'), self::groups($jobs, 'exception_class')];
            $payloadHidden = false;
        } else {
            [[$total, $byJobClass, $byExceptionClass], $payloadHidden] = $this->fromTable($source, false, function (Connection $ro, array $sql) use ($source, $from, $to): array {
                [$where, $bindings] = self::where($ro, $sql, new FailedJobFilter(from: $from, to: $to));
                $table = $ro->getQueryGrammar()->wrapTable((string) $source->table);

                // Grouped in a subquery, so ORDER BY names only the group (MySQL's ONLY_FULL_GROUP_BY, PostgreSQL's
                // rule for ORDER BY expressions). NULL last on every driver, then by name: the file source's order.
                $group = fn (string $expression, string $alias): array => self::select($ro, "select g as {$alias}, count(*) as failures "
                    ."from (select {$expression} as g from {$table}{$where}) grouped group by g "
                    .'order by failures desc, g is null, g limit '.(self::GROUPS + 1), $bindings);
                $count = self::select($ro, "select count(*) as total from {$table}{$where}", $bindings);

                return [
                    $count[0]['total'] ?? 0,
                    $sql['job_class'] === 'NULL' ? [] : $group($sql['job_class'], 'job_class'),
                    $group($sql['exception_class'], 'exception_class'),
                ];
            });
        }

        $jobClasses = [];
        $exceptionClasses = [];

        foreach (array_slice($byJobClass, 0, self::GROUPS) as $row) {
            $jobClasses[] = ['job_class' => self::text(self::field($row, 'job_class')), 'count' => self::count(self::field($row, 'failures'))];
        }

        foreach (array_slice($byExceptionClass, 0, self::GROUPS) as $row) {
            $exceptionClasses[] = ['exception_class' => self::text(self::field($row, 'exception_class')), 'count' => self::count(self::field($row, 'failures'))];
        }

        return [
            'total' => self::count($total),
            'by_job_class' => $jobClasses,
            'by_exception_class' => $exceptionClasses,
            'more_job_classes' => count($byJobClass) > self::GROUPS,
            'more_exception_classes' => count($byExceptionClass) > self::GROUPS,
            'payload_hidden' => $payloadHidden,
        ];
    }

    /**
     * Runs $read in the session frame, after the denied_tables check. A refused read is run once more without the
     * payload column (01-11's docs allow a grant without it): job_class is then NULL. With a job_class filter there
     * is nothing to match, so that is a FailedJobsUnavailable.
     *
     * @template T
     *
     * @param  Closure(Connection, array{job_class_full: string, job_class: string, first_line: string, exception_class: string}): T  $read
     * @return array{T, bool} the result and whether the payload was left out
     *
     * @throws FailedJobsUnavailable
     */
    private function fromTable(FailedJobSource $source, bool $needsPayload, Closure $read): array
    {
        if ($source->kind !== FailedJobSource::TABLE) {
            throw new FailedJobsUnavailable((string) $source->note);
        }

        $table = (string) $source->table;

        try {
            $denied = DeniedTables::fromConfig($this->config);
        } catch (InvalidGrantConfig) {
            // Fail closed (Sina, 2026-10-19): never a read without the denied-table filter.
            throw new FailedJobsUnavailable(self::BAD_DENIED_TABLES);
        }

        $run = fn (bool $payload): mixed => $this->session->run(function (Connection $ro) use ($denied, $table, $payload, $read): mixed {
            self::refuseDenied($ro, $denied, $table);

            return $read($ro, self::expressions($ro, $payload));
        });

        try {
            try {
                return [$run(true), false];
            } catch (QueryPermissionDenied $e) {
                if ($needsPayload) {
                    // A refused payload column, or a refused table: the second read below would say which.
                    $run(false);

                    throw new FailedJobsUnavailable(sprintf(self::PAYLOAD_FILTER, $table), 0, $e);
                }

                // A new frame: on PostgreSQL the refusal aborted the first one.
                return [$run(false), true];
            }
        } catch (UnsupportedDriver $e) {
            throw new FailedJobsUnavailable(sprintf(self::UNSUPPORTED, $e->driver), 0, $e);
        } catch (QueryRejected $e) {
            throw new FailedJobsUnavailable($this->rejected($e, $source), 0, $e);
        }
    }

    /** Spec 5.1: a table in denied_tables is never read, also when the role could (a relaxed default connection). */
    private static function refuseDenied(Connection $ro, DeniedTables $denied, string $table): void
    {
        $parts = explode('.', $table, 2);
        [$schemas, $name] = count($parts) === 2 ? [[$parts[0]], $parts[1]] : [SchemaSnapshot::appSchemas($ro), $table];

        // A bare name in denied_tables matches in any schema, so '' still finds it when no app schema is known. The
        // real name (prefix + name), so that both the real name and the name without the prefix match (DeniedTables).
        foreach ($schemas === [] ? [''] : $schemas as $schema) {
            if ($denied->contains($schema, $ro->getTablePrefix().$name, $ro->getTablePrefix())) {
                throw new FailedJobsUnavailable(sprintf(self::DENIED, $table));
            }
        }
    }

    /** Never the database's message (DbSchema's rule); the fixed ReadOnlySession texts say what happened. */
    private function rejected(QueryRejected $e, FailedJobSource $source): string
    {
        $table = (string) $source->table;
        $code = fn (array $codes): bool => in_array($e->sqlState, $codes, true) || in_array($e->driverCode, $codes, true);

        return match (true) {
            $e instanceof QueryTimedOut => sprintf(self::TIMED_OUT, $table, $e->timeoutMs),
            $e instanceof QueryPermissionDenied => sprintf(self::NOT_READABLE, $table),
            $e instanceof QueryFailed && in_array($e->getMessage(), ReadOnlySession::FIXED_NOTES, true) => $e->getMessage(),
            $e instanceof QueryFailed && $code(self::MISSING_TABLE) => sprintf(self::NOT_READABLE, $table),
            $e instanceof QueryFailed && $code(self::MISSING_COLUMN) && $source->idColumn === 'uuid' => sprintf(self::NO_UUID, $table),
            $e instanceof QueryFailed && $code(self::BAD_JSON) => sprintf(self::INVALID_PAYLOAD, $table),
            default => $this->unexpected($e),
        };
    }

    private function unexpected(QueryRejected $e): string
    {
        // The app's log gets the whole exception (spec 4.4); the agent gets the SQLSTATE.
        $this->exceptions->report($e);

        return sprintf(self::REFUSED, $e->sqlState);
    }

    /**
     * The projections, one place per driver. CHAR(10) and chr(10), never '\n': under MySQL's NO_BACKSLASH_ESCAPES
     * '\n' is two characters. A class name has no space and no ": ", and PHP writes "Class: message in /path:line",
     * or "Class in /path:line" for an empty message, so the class is the text before ": ", then before a space.
     *
     * @return array{job_class_full: string, job_class: string, first_line: string, exception_class: string}
     */
    private static function expressions(Connection $ro, bool $payload): array
    {
        $grammar = $ro->getQueryGrammar();
        $p = $grammar->wrap('payload');
        $e = $grammar->wrap('exception');
        $n = self::NAME_CHARACTERS;
        $raw = self::RAW_LINE_CHARACTERS;

        if ($ro->getDriverName() === 'pgsql') {
            $class = "({$p})::json ->> 'displayName'";
            $line = "split_part({$e}, chr(10), 1)";

            return [
                'job_class_full' => $payload ? $class : 'NULL',
                'job_class' => $payload ? "left({$class}, {$n})" : 'NULL',
                'first_line' => "left({$line}, {$raw})",
                'exception_class' => "left(split_part(split_part({$line}, ': ', 1), ' ', 1), {$n})",
            ];
        }

        $class = "JSON_UNQUOTE(JSON_EXTRACT({$p}, '$.displayName'))";
        $line = "SUBSTRING_INDEX({$e}, CHAR(10), 1)";

        return [
            'job_class_full' => $payload ? $class : 'NULL',
            'job_class' => $payload ? "LEFT({$class}, {$n})" : 'NULL',
            'first_line' => "LEFT({$line}, {$raw})",
            'exception_class' => "LEFT(SUBSTRING_INDEX(SUBSTRING_INDEX({$line}, ': ', 1), ' ', 1), {$n})",
        ];
    }

    /**
     * The WHERE clause of the filters and its bindings. Every value is a binding, never interpolated; a bound is
     * bound as Laravel writes failed_at (Y-m-d H:i:s in the app timezone).
     *
     * @param  array{job_class_full: string, job_class: string, first_line: string, exception_class: string}  $sql
     * @return array{string, list<string>}
     */
    private static function where(Connection $ro, array $sql, FailedJobFilter $filter): array
    {
        $g = $ro->getQueryGrammar();
        $clauses = [];
        $bindings = [];

        foreach ([[$filter->from, '>='], [$filter->to, '<=']] as [$bound, $operator]) {
            if ($bound !== null) {
                $clauses[] = $g->wrap('failed_at')." {$operator} ?";
                $bindings[] = FailedJobWindow::binding($bound);
            }
        }

        foreach (['queue' => $filter->queue, 'connection' => $filter->connection, $sql['job_class_full'] => $filter->jobClass] as $column => $value) {
            if ($value !== null) {
                $clauses[] = ($column === $sql['job_class_full'] ? $column : $g->wrap($column)).' = ?';
                $bindings[] = $value;
            }
        }

        return [$clauses === [] ? '' : ' where '.implode(' and ', $clauses), $bindings];
    }

    /**
     * The rows as arrays, from the write PDO: never the read PDO, whose host would run outside the frame's
     * transaction (ReadableSchema's rule).
     *
     * @param  list<string>  $bindings
     * @return list<array<string, mixed>>
     */
    private static function select(Connection $ro, string $sql, array $bindings): array
    {
        return self::rows($ro->selectFromWriteConnection($sql, $bindings));
    }

    /**
     * The file source, newest first, with the same projections as SQL. Bounded by MAX_FILE_BYTES before
     * FileFailedJobProvider::all() reads it; the payload and the trace reach PHP for this source only.
     *
     * @return list<array{id: string, job_class: string|null, queue: string, connection: string, failed_at: CarbonImmutable, first_line: string, exception_class: string}>
     *
     * @throws FailedJobsUnavailable
     */
    private function fileJobs(FailedJobSource $source): array
    {
        $failer = $this->failer();

        if (! $failer instanceof FileFailedJobProvider) {
            throw new FailedJobsUnavailable(self::REBOUND);
        }

        // Laravel's default when queue.failed.path is unset (QueueServiceProvider). Never shown: spec 4.3.
        $path = $source->path ?? $this->app->storagePath('framework/cache/failed-jobs.json');
        clearstatcache(true, $path);

        if (! file_exists($path)) {
            return []; // Laravel's read() gives [] too: nothing has failed yet.
        }

        $size = is_readable($path) ? filesize($path) : false;

        if ($size === false) {
            throw new FailedJobsUnavailable(self::FILE_UNREADABLE);
        }

        if ($size > self::MAX_FILE_BYTES) {
            throw new FailedJobsUnavailable(sprintf(self::FILE_TOO_LARGE, $size, self::MAX_FILE_BYTES));
        }

        $all = $failer->all();

        // Laravel's read() also gives [] for a blank file and for content that is not a JSON list (a damaged file, or
        // a read during a write): only the "[]" Laravel writes for no jobs is empty (rule 11).
        if ($all === []) {
            self::confirmNoJobs($path);
        }

        $jobs = [];

        foreach ($all as $index => $job) {
            $jobs[] = self::fileJob($job) + ['index' => $index];
        }

        // Newest first; the file is newest first already (log() puts each job at the start), so ties keep its order.
        usort($jobs, fn (array $a, array $b): int => [$b['failed_at'], $a['index']] <=> [$a['failed_at'], $b['index']]);

        return array_map(function (array $job): array {
            unset($job['index']);

            return $job;
        }, $jobs);
    }

    /**
     * FileFailedJobProvider::all() gave []: true only for a JSON empty list. Read again, at most MAX_FILE_BYTES + 1.
     *
     * @throws FailedJobsUnavailable
     */
    private static function confirmNoJobs(string $path): void
    {
        clearstatcache(true, $path);

        if (! file_exists($path)) {
            return; // Removed since the size check: nothing to read, as Laravel's read().
        }

        $content = file_get_contents($path, false, null, 0, self::MAX_FILE_BYTES + 1);

        if ($content === false) {
            throw new FailedJobsUnavailable(self::FILE_UNREADABLE);
        }

        $decoded = json_decode($content);

        if ($decoded === []) {
            return;
        }

        // Blank, or a list now (a worker wrote it between the two reads): try again. Anything else: not Laravel's format.
        throw new FailedJobsUnavailable(trim($content) === '' || is_array($decoded) ? self::FILE_BUSY : self::FILE_FORMAT);
    }

    /** The app's failed-job provider: mixed, because an app can bind its own (queue.failer is the interface's alias). */
    private function failer(): mixed
    {
        return $this->app->make(FailedJobProviderInterface::class);
    }

    /**
     * @return array{id: string, job_class: string|null, queue: string, connection: string, failed_at: CarbonImmutable, first_line: string, exception_class: string}
     *
     * @throws FailedJobsUnavailable
     */
    private static function fileJob(mixed $job): array
    {
        $fields = is_object($job) ? get_object_vars($job) : [];
        $strings = [];

        foreach (['id', 'connection', 'queue', 'payload', 'exception'] as $key) {
            if (! is_string($fields[$key] ?? null)) {
                throw new FailedJobsUnavailable(self::FILE_FORMAT);
            }

            $strings[$key] = $fields[$key];
        }

        try {
            // The Unix time does not move when the app timezone changes later; failed_at is a local time. Only the
            // format Laravel writes: parse() reads '' as now and words as relative times.
            $failedAt = is_int($fields['failed_at_timestamp'] ?? null)
                ? CarbonImmutable::createFromTimestamp($fields['failed_at_timestamp'], date_default_timezone_get())
                : CarbonImmutable::createFromFormat('!Y-m-d H:i:s', is_string($fields['failed_at'] ?? null) ? $fields['failed_at'] : '', date_default_timezone_get());
        } catch (Throwable $e) {
            throw new FailedJobsUnavailable(self::FILE_FORMAT, 0, $e);
        }

        if ($failedAt === null) {
            throw new FailedJobsUnavailable(self::FILE_FORMAT);
        }

        $line = explode("\n", $strings['exception'], 2)[0];
        $decoded = json_decode($strings['payload'], true, 32);
        $class = is_array($decoded) && is_string($decoded['displayName'] ?? null) ? $decoded['displayName'] : null;

        return [
            'id' => $strings['id'],
            'job_class' => $class === null ? null : mb_substr($class, 0, self::NAME_CHARACTERS),
            'queue' => $strings['queue'],
            'connection' => $strings['connection'],
            'failed_at' => $failedAt,
            'first_line' => mb_substr($line, 0, self::RAW_LINE_CHARACTERS),
            'exception_class' => mb_substr(explode(' ', explode(': ', $line, 2)[0], 2)[0], 0, self::NAME_CHARACTERS),
        ];
    }

    /** @param  array{job_class: string|null, queue: string, connection: string, failed_at: CarbonImmutable}  $job */
    private static function matches(array $job, FailedJobFilter $filter): bool
    {
        return ($filter->jobClass === null || $job['job_class'] === $filter->jobClass)
            && ($filter->queue === null || $job['queue'] === $filter->queue)
            && ($filter->connection === null || $job['connection'] === $filter->connection)
            && ($filter->from === null || $job['failed_at'] >= $filter->from)
            && ($filter->to === null || $job['failed_at'] <= $filter->to);
    }

    /**
     * The file source's groups, in the SQL order: most failures first, NULL last, then by name.
     *
     * @param  array<array-key, array{job_class: string|null, exception_class: string}>  $jobs
     * @param  'job_class'|'exception_class'  $key
     * @return list<array<string, mixed>>
     */
    private static function groups(array $jobs, string $key): array
    {
        $counts = [];
        $names = [];

        foreach ($jobs as $job) {
            $name = $job[$key];
            $slot = $name === null ? "\0null" : 'v'.$name;
            $counts[$slot] = ($counts[$slot] ?? 0) + 1;
            $names[$slot] = $name;
        }

        $slots = array_keys($counts);
        usort($slots, fn (string $a, string $b): int => [$counts[$b], $names[$a] === null, (string) $names[$a]]
            <=> [$counts[$a], $names[$b] === null, (string) $names[$b]]);

        return array_map(fn (string $slot): array => [$key => $names[$slot], 'failures' => $counts[$slot]], array_slice($slots, 0, self::GROUPS + 1));
    }

    /**
     * Redacted whole, then cut to LINE_CHARACTERS, the last one "…" when cut (as DbQuery::quote()).
     */
    private static function shortLine(Redactor $redactor, string $line): string
    {
        $line = rtrim($redactor->redactString($line));

        return mb_strlen($line) > self::LINE_CHARACTERS ? mb_substr($line, 0, self::LINE_CHARACTERS - 1).'…' : $line;
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return list<array<string, mixed>>
     */
    private static function rows(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $values = [];

            foreach ((array) $row as $key => $value) {
                $values[(string) $key] = $value;
            }

            $out[] = $values;
        }

        return $out;
    }

    private static function field(mixed $row, string $key): mixed
    {
        return is_array($row) ? ($row[$key] ?? null) : null;
    }

    private static function text(mixed $value): ?string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value) => (string) $value,
            default => null,
        };
    }

    private static function count(mixed $value): int
    {
        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);
    }

    /** A failed_at from SQL (a local time string) or from the file (a Carbon), as ISO 8601 with an offset. */
    private static function iso(mixed $value): ?string
    {
        return $value instanceof CarbonImmutable || is_string($value) ? Timestamp::iso($value) : null;
    }
}
