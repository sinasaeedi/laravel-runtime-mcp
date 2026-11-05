<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins\Database\Tools;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Illuminate\Support\ConfigurationUrlParser;
use InvalidArgumentException;
use SinaSaeedi\RuntimeMcp\Plugins\Database\PermissionMessage;
use SinaSaeedi\RuntimeMcp\Plugins\Database\ReadableSchema;
use SinaSaeedi\RuntimeMcp\Plugins\Database\ResultValues;
use SinaSaeedi\RuntimeMcp\Security\Grants\InvalidGrantConfig;
use SinaSaeedi\RuntimeMcp\Security\Redaction\Redactor;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\NotInReadOnlyTransaction;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\QueryFailed;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\QueryNotLimitable;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\QueryPermissionDenied;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\QueryRejected;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\QueryTimedOut;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\ReadOnlyViolation;
use SinaSaeedi\RuntimeMcp\Security\Sql\MaxRowsSetting;
use SinaSaeedi\RuntimeMcp\Security\Sql\QueryResult;
use SinaSaeedi\RuntimeMcp\Security\Sql\QueryRunner;
use SinaSaeedi\RuntimeMcp\Security\Sql\TimeoutSetting;
use SinaSaeedi\RuntimeMcp\Security\UnsupportedDriver;
use SinaSaeedi\RuntimeMcp\Tools\Contracts\RuntimeTool;
use SinaSaeedi\RuntimeMcp\Tools\Tier;
use SinaSaeedi\RuntimeMcp\Tools\ToolInput;
use SinaSaeedi\RuntimeMcp\Tools\ToolResult;

/**
 * Spec 5.1, 5.2, 6.4 (01-20): the agent's own SQL on the read-only connection, through QueryRunner
 * (01-14, 01-15): a native prepared statement in a read-only transaction, under the statement timeout,
 * with the row limit inside the database, and a session reset after it. No SQL is parsed (rule 2).
 *
 * The database's refusals are results, not tool failures: the tool worked and the database said no, so
 * the agent gets the reason in the envelope (rule 11: never `empty`). An ok result has no note (01-08).
 *
 * Built through the container on every request (also tools/list), so the constructor reads nothing and
 * the description reads only config: tools/list never connects to the database.
 */
final class DbQuery extends RuntimeTool
{
    public const MAX_SQL = 10_000;

    /** The part of a database message that a note quotes: its first line, at most this many characters. */
    public const MESSAGE_CHARACTERS = 300;

    public const NO_ROWS = 'The query ran on the read-only connection and returned no rows.';

    public const MORE_ROWS = 'More than %1$d rows match; showing the first %1$d. Add a WHERE clause or a LIMIT to narrow the query.';

    public const MARIADB_ORDER = 'On MariaDB, put a LIMIT inside an ordered query, or the order may be lost.';

    public const NO_RESULT_SET = 'The statement ran, but it returns no result set. Only a SELECT-style statement returns rows.';

    public const TIMED_OUT = 'The query was stopped by the %d ms statement timeout. Narrow it (a WHERE on an indexed column, a LIMIT) and try again.';

    /** Spec 5.1, word for word. */
    public const UNREADABLE_COLUMNS = 'Table `%s` has columns that you cannot read. Select columns by name. Readable columns: %s.';

    /** As db_schema: on MySQL and MariaDB a missing table is also 1142, so the note never says it exists. */
    public const NOT_READABLE = DbSchema::NOT_READABLE;

    public const PERMISSION_DENIED = 'The database refused the query: permission denied. Use db_schema to see what you can read.';

    public const WROTE = 'The query tried to write. db_query runs in a read-only transaction, so nothing was changed.';

    public const EMPTY_QUERY = 'The query is empty. Send one SQL statement.';

    public const REJECTED = 'The database rejected the query (SQLSTATE %s): %s';

    public const UNSUPPORTED = 'db_query supports PostgreSQL, MySQL and MariaDB; this connection uses %s.';

    /** Where QueryNotLimitable's fixed text ends and the database's message begins (01-15 Q1). */
    public const DATABASE_SAID = 'The database said: ';

    public const MYSQL_LINE = 'Only SELECT-style queries run (no EXPLAIN or SHOW).';

    public const MARIADB_LINE = 'Put a LIMIT inside an ordered query, or the order may be lost.';

    public function __construct(
        private readonly QueryRunner $runner,
        private readonly ReadableSchema $schema,
        private readonly MaxRowsSetting $maxRows,
        private readonly TimeoutSetting $timeout,
        private readonly Repository $config,
        private readonly Container $container,
    ) {}

    public function name(): string
    {
        return 'db_query';
    }

    public function description(): string
    {
        $rows = $this->maxRows->rows();
        $text = 'Runs one read-only SQL query on the application\'s read-only database connection and returns columns '
            .'and rows. Writes are blocked by the database, and locks and session settings end with the call; the query '
            .'is stopped after '.$this->timeout->milliseconds().' ms (the statement timeout); at most '.$rows.' rows are '
            .'returned. Tables or columns you cannot read return an error that lists the readable columns. Input: sql '
            .'(at most 10,000 characters), max_rows (optional, at most '.$rows.').';
        $driver = $this->driver();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $text .= ' '.self::MYSQL_LINE;
        }

        if ($driver === 'mariadb') {
            $text .= ' '.self::MARIADB_LINE;
        }

        return $text;
    }

    public function tier(): Tier
    {
        return Tier::ReadSensitive;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'sql' => $schema->string()
                ->description('One SQL statement, for example SELECT id, number FROM orders WHERE id = 1234.')
                ->max(self::MAX_SQL)
                ->required(),
            'max_rows' => $schema->integer()
                ->description('The most rows to return. Leave it out for the server maximum.')
                ->min(1)
                ->max($this->maxRows->rows()),
        ];
    }

    public function rules(): array
    {
        // required refuses null, "" and blanks (it trims). max counts characters, as maxLength does.
        return [
            'sql' => ['required', 'string', 'max:'.self::MAX_SQL],
            // numeric refuses true, which integer takes as 1 (filter_var) and is_numeric() then drops.
            'max_rows' => ['integer', 'numeric', 'min:1', 'max:'.$this->maxRows->rows()],
        ];
    }

    public function execute(ToolInput $input): ToolResult
    {
        $sql = $input->get('sql');
        $maxRows = $input->get('max_rows');

        try {
            $result = $this->runner->run(is_string($sql) ? $sql : '', is_numeric($maxRows) ? (int) $maxRows : null);
        } catch (UnsupportedDriver $e) {
            return ToolResult::unavailable(sprintf(self::UNSUPPORTED, $e->driver));
        } catch (QueryRejected $e) {
            return ToolResult::unavailable($this->rejected($e));
        }

        // NotInReadOnlyTransaction and anything else pass: RunTool (01-08) reports a failed call.
        return self::answer($result);
    }

    private static function answer(QueryResult $result): ToolResult
    {
        // PostgreSQL runs a statement that cannot be wrapped (SET, LOCK) bare (01-15): not "no rows" (rule 11).
        if ($result->columns === []) {
            return ToolResult::unavailable(self::NO_RESULT_SET);
        }

        $data = [
            'columns' => $result->columns,
            'rows' => ResultValues::convert($result),
            'row_count' => $result->rowCount,
            'truncated' => $result->hasMore,
        ];

        if ($result->hasMore) {
            // The extra row proves that more rows match, not how many: truncated stays false (principle 1.4.8).
            $note = sprintf(self::MORE_ROWS, $result->rowCount).($result->orderMayBeLost ? ' '.self::MARIADB_ORDER : '');

            return ToolResult::partial($data, $note, false, $result->rowCount)->withListKey('rows');
        }

        if ($result->rowCount === 0) {
            return ToolResult::empty(self::NO_ROWS, $data)->withListKey('rows');
        }

        return ToolResult::ok($data, $result->rowCount)->withListKey('rows');
    }

    private function rejected(QueryRejected $e): string
    {
        return match (true) {
            $e instanceof QueryTimedOut => sprintf(self::TIMED_OUT, $e->timeoutMs),
            $e instanceof QueryPermissionDenied => $this->permission($e),
            $e instanceof ReadOnlyViolation => self::WROTE,
            $e instanceof QueryNotLimitable => self::notLimitable($this->redacted($e)),
            $e instanceof QueryFailed && $e->getMessage() === QueryRunner::EMPTY => self::EMPTY_QUERY,
            // Package text that says what happened, never "the database rejected the query" (01-14 re-review).
            $e instanceof QueryFailed && in_array($e->getMessage(), DbSchema::FIXED_NOTES, true) => $e->getMessage(),
            default => sprintf(self::REJECTED, $e->sqlState, self::sentence(self::quote($this->redacted($e)))),
        };
    }

    /**
     * The database's message, redacted before quote() cuts it, as RunTool::cut(): a secret cut in half
     * (a password with no "@" after it) matches no pattern in RedactStage. Resolved here, not in the
     * constructor: a bad extra_patterns entry must fail this call, not tools/list.
     */
    private function redacted(QueryRejected $e): string
    {
        return $this->container->make(Redactor::class)->redactString($e->getMessage());
    }

    /** Spec 5.1: the readable columns of the table the error names, read after the failed query. */
    private function permission(QueryPermissionDenied $e): string
    {
        $table = PermissionMessage::table($e);

        if ($table === null) {
            return self::PERMISSION_DENIED;
        }

        try {
            $columns = $this->schema->readableColumns($table);
            $every = $columns !== [] && $this->schema->readsEveryColumn($table);
        } catch (InvalidGrantConfig|UnsupportedDriver|QueryRejected|NotInReadOnlyTransaction) {
            // A timeout, a lost connection, a bad denied_tables: the call still answers (01-19 audit).
            return self::PERMISSION_DENIED;
        }

        if ($columns === []) {
            return sprintf(self::NOT_READABLE, $table);
        }

        // Every column is readable, so the error is about another table of that name (a bare name from
        // MySQL 1142 or PostgreSQL 42501) or another privilege (FOR UPDATE): its columns would mislead.
        return $every
            ? self::PERMISSION_DENIED
            : sprintf(self::UNREADABLE_COLUMNS, $table, implode(', ', $columns));
    }

    /**
     * 01-15: fixed text, then "The database said: <the database's message>"; only that message is cut.
     * Public for DbQueryTest: no MySQL or MariaDB message reaches the cut (measured: one line, at most 228).
     */
    public static function notLimitable(string $message): string
    {
        $at = strpos($message, self::DATABASE_SAID);

        if ($at === false) {
            return self::quote($message);
        }

        $at += strlen(self::DATABASE_SAID);

        return substr($message, 0, $at).self::quote(substr($message, $at));
    }

    /** The first line of a database message, at most MESSAGE_CHARACTERS characters (the last one "…" when cut). */
    public static function quote(string $message): string
    {
        $line = rtrim(explode("\n", $message, 2)[0]);

        return mb_strlen($line) > self::MESSAGE_CHARACTERS
            ? mb_substr($line, 0, self::MESSAGE_CHARACTERS - 1).'…'
            : $line;
    }

    /** One full stop at the end, none after a cut. */
    private static function sentence(string $text): string
    {
        return str_ends_with($text, '…') ? $text : rtrim($text, '.').'.';
    }

    /**
     * The read-only connection's driver, from config only, as DatabaseManager finds it (Arr::get(), and a
     * url can set the driver). Unset or '' gives the default connection, as ReadOnlySession. Null when
     * the connection is not configured: the description then adds nothing, and never throws.
     */
    private function driver(): ?string
    {
        $name = $this->config->get('runtime-mcp.database.connection');
        $name = is_string($name) && $name !== '' ? $name : $this->config->get('database.default');
        $connections = $this->config->get('database.connections');
        $settings = is_string($name) && is_array($connections) ? Arr::get($connections, $name) : null;

        if (is_array($settings)) {
            $settings = array_filter($settings, is_string(...), ARRAY_FILTER_USE_KEY);
        } elseif (! is_string($settings)) {
            return null;
        }

        try {
            $driver = (new ConfigurationUrlParser)->parseConfiguration($settings)['driver'] ?? null;
        } catch (InvalidArgumentException) {
            // A malformed url: the call reports the connection inside the pipeline, as for db_schema.
            return null;
        }

        return is_string($driver) ? $driver : null;
    }
}
