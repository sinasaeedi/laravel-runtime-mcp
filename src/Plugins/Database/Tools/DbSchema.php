<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins\Database\Tools;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use SinaSaeedi\RuntimeMcp\Plugins\Database\ReadableSchema;
use SinaSaeedi\RuntimeMcp\Security\Grants\InvalidGrantConfig;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\QueryFailed;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\QueryRejected;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\QueryTimedOut;
use SinaSaeedi\RuntimeMcp\Security\Sql\ReadOnlySession;
use SinaSaeedi\RuntimeMcp\Security\UnsupportedDriver;
use SinaSaeedi\RuntimeMcp\Tools\Contracts\RuntimeTool;
use SinaSaeedi\RuntimeMcp\Tools\Tier;
use SinaSaeedi\RuntimeMcp\Tools\ToolInput;
use SinaSaeedi\RuntimeMcp\Tools\ToolResult;

/**
 * Spec 6.3 (01-19): the tables the read-only role can read, or one table's columns (with readable),
 * indexes and foreign keys. A missing, denied or unreadable table gets one sentence, so the tool does
 * not say that a denied table exists; a table it could not look at is never `empty` (rule 11).
 *
 * Built through the container on every request (also tools/list), so the constructor reads nothing.
 */
final class DbSchema extends RuntimeTool
{
    public const MAX_TABLE = 128;

    /** "name" or "schema.name". JSON schema dialect (no delimiters); rules() adds /…/D. */
    public const TABLE_PATTERN = '^[A-Za-z0-9_$]+(\.[A-Za-z0-9_$]+)?$';

    public const NOT_READABLE = 'Table `%s` does not exist, or the read-only role cannot read it.';

    public const NO_TABLE = 'The read-only role can read no table in schema %s.';

    public const NO_SCHEMA = 'The search_path of the read-only connection names only system schemas, so db_schema has no schema to list.';

    public const BAD_DENIED_TABLES = 'runtime-mcp.database.denied_tables is not a list of names, so db_schema shows no table. Fix the config.';

    public const UNSUPPORTED = 'db_schema supports PostgreSQL, MySQL and MariaDB; this connection uses %s.';

    public const TIMED_OUT = 'Reading the schema was stopped by the %d ms statement timeout.';

    public const REFUSED = 'The database refused the schema read (SQLSTATE %s).';

    /** ReadOnlySession's fixed notes: package text, safe to show as they are (as 01-20). */
    public const FIXED_NOTES = ReadOnlySession::FIXED_NOTES;

    public function __construct(
        private readonly ReadableSchema $schema,
        private readonly ExceptionHandler $exceptions,
    ) {}

    public function name(): string
    {
        return 'db_schema';
    }

    public function description(): string
    {
        return 'Lists the database tables the read-only role can read, or, with `table`, that table\'s columns (name, '
            .'type, nullable, default, readable), indexes and foreign keys. A column with readable=false exists but '
            .'cannot be selected; on MySQL and MariaDB such columns are not listed at all. Denied tables never appear. '
            .'Input: table (optional, at most 128 characters, "name" or "schema.name").';
    }

    public function tier(): Tier
    {
        return Tier::Read;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'table' => $schema->string()
                ->description('A table name, or schema.name. Leave it out to list the tables.')
                ->max(self::MAX_TABLE)
                ->pattern(self::TABLE_PATTERN),
        ];
    }

    public function rules(): array
    {
        // Array form: a rule string splits on "|". filled: the validator skips the other rules for "" (and
        // null), so without it an empty name reached execute(). /D: without it, $ also matches before a final "\n".
        return ['table' => ['filled', 'string', 'max:'.self::MAX_TABLE, 'regex:/'.self::TABLE_PATTERN.'/D']];
    }

    public function execute(ToolInput $input): ToolResult
    {
        $table = $input->get('table');

        try {
            return is_string($table) ? $this->describe($table) : $this->list();
        } catch (InvalidGrantConfig) {
            // Fail closed (Sina, 2026-10-19): never a list without the denied-table filter.
            return ToolResult::unavailable(self::BAD_DENIED_TABLES);
        } catch (UnsupportedDriver $e) {
            return ToolResult::unavailable(sprintf(self::UNSUPPORTED, $e->driver));
        } catch (QueryRejected $e) {
            return ToolResult::unavailable($this->rejected($e));
        }
    }

    private function list(): ToolResult
    {
        $tables = $this->schema->tables();

        if ($tables === []) {
            $schemas = $this->schema->appSchemas();

            return ToolResult::empty($schemas === [] ? self::NO_SCHEMA : sprintf(self::NO_TABLE, implode(', ', $schemas)));
        }

        return ToolResult::ok(['tables' => $tables], count($tables))->withListKey('tables');
    }

    private function describe(string $table): ToolResult
    {
        $description = $this->schema->describe($table);

        if ($description === null) {
            return ToolResult::unavailable(sprintf(self::NOT_READABLE, $table));
        }

        return ToolResult::ok($description->toArray(), count($description->columns))->withListKey('columns');
    }

    /** Never the database's message: a catalog error can name a host or a user. */
    private function rejected(QueryRejected $e): string
    {
        if ($e instanceof QueryTimedOut) {
            return sprintf(self::TIMED_OUT, $e->timeoutMs);
        }

        if ($e instanceof QueryFailed && in_array($e->getMessage(), self::FIXED_NOTES, true)) {
            return $e->getMessage();
        }

        // Unexpected: the app's log gets the whole exception (spec 4.4).
        $this->exceptions->report($e);

        return sprintf(self::REFUSED, $e->sqlState);
    }
}
