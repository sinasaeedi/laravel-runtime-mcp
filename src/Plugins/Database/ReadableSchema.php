<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins\Database;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Grammars\Grammar;
use SinaSaeedi\RuntimeMcp\Security\Grants\DeniedTables;
use SinaSaeedi\RuntimeMcp\Security\Grants\InvalidGrantConfig;
use SinaSaeedi\RuntimeMcp\Security\Grants\SchemaSnapshot;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\NotInReadOnlyTransaction;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\QueryPermissionDenied;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\QueryRejected;
use SinaSaeedi\RuntimeMcp\Security\Sql\ReadOnlySession;
use SinaSaeedi\RuntimeMcp\Security\UnsupportedDriver;

/**
 * Spec 4.2, 5.1, 6.3 (01-19): the schema as the read-only role sees it. db_schema shows it; 01-20 names
 * the readable columns in its permission message; 01-21 selects exactly readableColumns().
 *
 * Every query runs on the read-only connection inside ReadOnlySession::run() (Q1): the statement timeout,
 * the read-only transaction, the strict-environment and persistent-connection refusals and the reset of
 * 01-14 apply to these fixed catalog queries too. Names are bound, or escaped for the schema grammar,
 * which quotes without escaping. Names in and out are the real catalog names, with the table prefix.
 *
 * Not bound in the container, and never a singleton (rule 14): ReadOnlySession needs the request's ledger.
 */
final class ReadableSchema
{
    public function __construct(
        private readonly ReadOnlySession $session,
        private readonly Repository $config,
    ) {}

    /**
     * The tables and views the role can read in the app schemas, denied tables left out.
     *
     * @return list<array{schema: string, name: string, type: 'table'|'view'}>
     *
     * @throws InvalidGrantConfig
     * @throws UnsupportedDriver
     * @throws QueryRejected
     * @throws NotInReadOnlyTransaction
     */
    public function tables(): array
    {
        $denied = DeniedTables::fromConfig($this->config);

        return $this->session->run(function (Connection $ro) use ($denied): array {
            $schemas = SchemaSnapshot::appSchemas($ro);

            return $schemas === [] ? [] : $this->rows($ro, $denied, $schemas, null);
        });
    }

    /**
     * PostgreSQL: the search_path; MySQL and MariaDB: the database. For db_schema's empty note.
     *
     * @return list<string>
     *
     * @throws UnsupportedDriver
     * @throws QueryRejected
     * @throws NotInReadOnlyTransaction
     */
    public function appSchemas(): array
    {
        return $this->session->run(fn (Connection $ro): array => SchemaSnapshot::appSchemas($ro));
    }

    /**
     * Null when the table does not exist, is denied, or the role cannot read any of it.
     *
     * @param  string  $table  "name" or "schema.name"
     *
     * @throws InvalidGrantConfig
     * @throws UnsupportedDriver
     * @throws QueryRejected
     * @throws NotInReadOnlyTransaction
     */
    public function describe(string $table): ?TableDescription
    {
        $denied = DeniedTables::fromConfig($this->config);

        // One run(): MySQL and MariaDB disconnect after each one.
        return $this->session->run(function (Connection $ro) use ($denied, $table): ?TableDescription {
            $found = $this->find($ro, $denied, $table);

            if ($found === null) {
                return null;
            }

            ['schema' => $schema, 'name' => $name] = $found;
            $columns = $this->columns($ro, $schema, $name);
            $visible = array_column($columns, 'name');

            return new TableDescription(
                $schema,
                $name,
                $found['type'],
                $columns,
                $this->indexes($ro, $schema, $name, $visible),
                $this->foreignKeys($ro, $denied, $schema, $name, $visible),
            );
        });
    }

    /**
     * The columns the role can SELECT, in table order; [] when the table is unknown, denied or unreadable.
     *
     * @param  string  $table  "name" or "schema.name"
     * @return list<string>
     *
     * @throws InvalidGrantConfig
     * @throws UnsupportedDriver
     * @throws QueryRejected
     * @throws NotInReadOnlyTransaction
     */
    public function readableColumns(string $table): array
    {
        $denied = DeniedTables::fromConfig($this->config);

        return $this->session->run(function (Connection $ro) use ($denied, $table): array {
            $found = $this->find($ro, $denied, $table);

            if ($found === null) {
                return [];
            }

            $names = [];

            foreach ($this->columns($ro, $found['schema'], $found['name']) as $column) {
                if ($column['readable']) {
                    $names[] = $column['name'];
                }
            }

            return $names;
        });
    }

    /**
     * Whether the role can SELECT every column of the table: the database's own check, by a SELECT * that
     * returns no row (rule 13: the grants are the one column mechanism). MySQL and MariaDB show the role
     * only its readable columns, so the catalog cannot tell. False when the table is unknown, denied or
     * has a column the role cannot read.
     *
     * 01-20 triage: a permission error that names a bare table can be about another table of that name
     * (another database or schema) or another privilege (MySQL SELECT … FOR UPDATE).
     *
     * @param  string  $table  "name" or "schema.name"
     *
     * @throws InvalidGrantConfig
     * @throws UnsupportedDriver
     * @throws QueryRejected
     * @throws NotInReadOnlyTransaction
     */
    public function readsEveryColumn(string $table): bool
    {
        $denied = DeniedTables::fromConfig($this->config);

        try {
            return $this->session->run(function (Connection $ro) use ($denied, $table): bool {
                $found = $this->find($ro, $denied, $table);

                if ($found === null) {
                    return false;
                }

                self::select($ro, 'select * from '.self::identifier($ro, $found['schema']).'.'.self::identifier($ro, $found['name']).' limit 0');

                return true;
            });
        } catch (QueryPermissionDenied) {
            return false;
        }
    }

    /**
     * A bare name is looked up in the app schemas in their order (the search_path order); the first
     * schema with a readable table of that name wins. A schema outside the app schemas finds nothing.
     *
     * @return array{schema: string, name: string, type: 'table'|'view'}|null
     */
    private function find(Connection $ro, DeniedTables $denied, string $table): ?array
    {
        $schemas = SchemaSnapshot::appSchemas($ro);
        $parts = explode('.', $table, 2);

        if (count($parts) === 2) {
            if (! in_array($parts[0], $schemas, true)) {
                return null;
            }

            $schemas = [$parts[0]];
            $table = $parts[1];
        }

        if ($schemas === []) {
            return null;
        }

        $rows = $this->rows($ro, $denied, $schemas, $table);

        foreach ($schemas as $schema) {
            foreach ($rows as $row) {
                if ($row['schema'] === $schema) {
                    return $row;
                }
            }
        }

        return null;
    }

    /**
     * The readable tables and views of $schemas (only those named $name, when given), denied ones left out,
     * sorted by schema and name in byte order.
     *
     * @param  non-empty-list<string>  $schemas
     * @return list<array{schema: string, name: string, type: 'table'|'view'}>
     */
    private function rows(Connection $ro, DeniedTables $denied, array $schemas, ?string $name): array
    {
        $in = implode(', ', array_fill(0, count($schemas), '?'));
        $bindings = $name === null ? $schemas : [...$schemas, $name];

        if ($ro->getDriverName() === 'pgsql') {
            // A partition or a view gets no grant from 01-11, so has_any_column_privilege() hides it.
            $sql = 'select n.nspname as schema, c.relname as name, c.relkind as kind '
                .'from pg_class c join pg_namespace n on n.oid = c.relnamespace '
                ."where c.relkind in ('r', 'p', 'v', 'm') and n.nspname in ({$in}) "
                ."and has_any_column_privilege(c.oid, 'SELECT')"
                .($name === null ? '' : ' and c.relname = ?');
        } else {
            // The server lists only the tables the role has a privilege on (probed on MySQL and MariaDB).
            $sql = 'select table_schema as `schema`, table_name as `name`, table_type as `kind` '
                ."from information_schema.tables where table_schema in ({$in}) "
                ."and table_type in ('BASE TABLE', 'SYSTEM VERSIONED', 'VIEW')"
                .($name === null ? '' : ' and table_name = ?');
        }

        $prefix = $ro->getTablePrefix();
        $tables = [];

        foreach (self::select($ro, $sql, $bindings) as $row) {
            $schema = self::text($row['schema'] ?? null);
            $table = self::text($row['name'] ?? null);

            if ($schema === '' || $table === '' || $denied->contains($schema, $table, $prefix)) {
                continue;
            }

            $type = in_array(self::text($row['kind'] ?? null), ['v', 'm', 'VIEW'], true) ? 'view' : 'table';
            $tables[] = ['schema' => $schema, 'name' => $table, 'type' => $type];
        }

        usort($tables, fn (array $a, array $b): int => strcmp($a['schema'], $b['schema']) ?: strcmp($a['name'], $b['name']));

        return $tables;
    }

    /**
     * Laravel's own catalog query, through the grammar: Builder::getColumns() would add the table prefix
     * to a name that already holds it. PostgreSQL lists every column, with readable from the grants;
     * MySQL and MariaDB list only the columns the role can read.
     *
     * @return list<array{name: string, type: string, nullable: bool, default: mixed, readable: bool}>
     */
    private function columns(Connection $ro, string $schema, string $name): array
    {
        $sql = self::grammar($ro)->compileColumns(self::escaped($ro, $schema), self::escaped($ro, $name));
        $readable = $ro->getDriverName() === 'pgsql' ? $this->pgsqlReadable($ro, $schema, $name) : null;
        $columns = [];

        foreach ($ro->getPostProcessor()->processColumns(self::select($ro, $sql)) as $column) {
            $columnName = self::text($column['name']);

            if ($columnName === '') {
                continue;
            }

            $columns[] = [
                'name' => $columnName,
                'type' => self::text($column['type']),
                'nullable' => $column['nullable'],
                'default' => $column['default'],
                'readable' => $readable === null || ($readable[$columnName] ?? false),
            ];
        }

        return $columns;
    }

    /**
     * By attnum, not by name: a name is text that the function would read again.
     *
     * @return array<string, bool>
     */
    private function pgsqlReadable(Connection $ro, string $schema, string $name): array
    {
        $rows = self::select(
            $ro,
            "select a.attname as name, has_column_privilege(c.oid, a.attnum, 'SELECT') as readable "
            .'from pg_attribute a join pg_class c on c.oid = a.attrelid join pg_namespace n on n.oid = c.relnamespace '
            .'where n.nspname = ? and c.relname = ? and a.attnum > 0 and not a.attisdropped',
            [$schema, $name],
        );

        $readable = [];

        foreach ($rows as $row) {
            $readable[self::text($row['name'] ?? null)] = in_array($row['readable'] ?? null, [true, 1, '1', 't', 'true'], true);
        }

        return $readable;
    }

    /**
     * An index that names a column outside $visible is left out (Q2): MySQL lists an index on a column
     * the role cannot see (probed on 8.4), and its name and columns would show that column. On PostgreSQL
     * every column is visible, so nothing is left out there.
     *
     * MySQL gives an expression key part no column name, and Laravel's group_concat leaves it out, so an
     * index on lower(api_token) would pass with no column at all: an index with such a part is left out too.
     *
     * @param  list<string>  $visible
     * @return list<array{name: string, columns: list<string>, unique: bool, primary: bool}>
     */
    private function indexes(Connection $ro, string $schema, string $name, array $visible): array
    {
        $sql = self::grammar($ro)->compileIndexes(self::escaped($ro, $schema), self::escaped($ro, $name));
        $expressions = $ro->getDriverName() === 'pgsql' ? [] : array_map(
            fn (array $row): string => strtolower(self::text($row['name'] ?? null)),
            self::select(
                $ro,
                'select distinct index_name as `name` from information_schema.statistics '
                .'where table_schema = ? and table_name = ? and column_name is null',
                [$schema, $name],
            ),
        );
        $indexes = [];

        foreach ($ro->getPostProcessor()->processIndexes(self::select($ro, $sql)) as $index) {
            $columns = array_map(self::text(...), $index['columns']);

            if (array_diff($columns, $visible) !== [] || in_array(self::text($index['name']), $expressions, true)) {
                continue;
            }

            $indexes[] = [
                'name' => self::text($index['name']),
                'columns' => $columns,
                'unique' => $index['unique'],
                'primary' => $index['primary'],
            ];
        }

        usort($indexes, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $indexes;
    }

    /**
     * A foreign key is left out when a local column is not visible, when its foreign table is denied
     * (spec 6.3: denied tables never appear), or, on MySQL and MariaDB, when a foreign column is not
     * readable (Q2). MariaDB shows no REFERENTIAL_CONSTRAINTS row to a SELECT-only role (probed on 11.4
     * and 10.11), so its foreign keys come from KEY_COLUMN_USAGE, with no ON UPDATE / ON DELETE rule (Q3).
     *
     * @param  list<string>  $visible
     * @return list<array{name: string|null, columns: list<string>, foreign_schema: string|null, foreign_table: string, foreign_columns: list<string>, on_update: string|null, on_delete: string|null}>
     */
    private function foreignKeys(Connection $ro, DeniedTables $denied, string $schema, string $name, array $visible): array
    {
        $pgsql = $ro->getDriverName() === 'pgsql';
        $rows = $ro instanceof MySqlConnection && $ro->isMaria()
            ? $this->mariaDbForeignKeys($ro, $schema, $name)
            : $ro->getPostProcessor()->processForeignKeys(self::select(
                $ro,
                self::grammar($ro)->compileForeignKeys(self::escaped($ro, $schema), self::escaped($ro, $name)),
            ));
        $prefix = $ro->getTablePrefix();
        $readableOf = [];
        $keys = [];

        foreach ($rows as $row) {
            $columns = array_map(self::text(...), $row['columns']);
            $foreignSchema = $row['foreign_schema'] === null ? null : self::text($row['foreign_schema']);
            $foreignTable = self::text($row['foreign_table']);
            $foreignColumns = array_map(self::text(...), $row['foreign_columns']);

            if (array_diff($columns, $visible) !== [] || $denied->contains($foreignSchema ?? $schema, $foreignTable, $prefix)) {
                continue;
            }

            if (! $pgsql) {
                $key = ($foreignSchema ?? $schema)."\0".$foreignTable;
                $readableOf[$key] ??= array_column($this->columns($ro, $foreignSchema ?? $schema, $foreignTable), 'name');

                if (array_diff($foreignColumns, $readableOf[$key]) !== []) {
                    continue;
                }
            }

            $keys[] = [
                'name' => $row['name'] === null ? null : self::text($row['name']),
                'columns' => $columns,
                'foreign_schema' => $foreignSchema,
                'foreign_table' => $foreignTable,
                'foreign_columns' => $foreignColumns,
                'on_update' => $row['on_update'] === null ? null : self::text($row['on_update']),
                'on_delete' => $row['on_delete'] === null ? null : self::text($row['on_delete']),
            ];
        }

        usort($keys, fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

        return $keys;
    }

    /** @return list<array{name: string|null, columns: list<string>, foreign_schema: string|null, foreign_table: string, foreign_columns: list<string>, on_update: null, on_delete: null}> */
    private function mariaDbForeignKeys(Connection $ro, string $schema, string $name): array
    {
        $rows = self::select(
            $ro,
            'select constraint_name as `name`, group_concat(column_name order by ordinal_position) as `columns`, '
            .'referenced_table_schema as `foreign_schema`, referenced_table_name as `foreign_table`, '
            .'group_concat(referenced_column_name order by ordinal_position) as `foreign_columns` '
            .'from information_schema.key_column_usage '
            .'where table_schema = ? and table_name = ? and referenced_table_name is not null '
            .'group by constraint_name, referenced_table_schema, referenced_table_name',
            [$schema, $name],
        );

        $keys = [];

        foreach ($rows as $row) {
            $keys[] = [
                'name' => self::text($row['name'] ?? null),
                'columns' => explode(',', self::text($row['columns'] ?? null)),
                'foreign_schema' => self::text($row['foreign_schema'] ?? null),
                'foreign_table' => self::text($row['foreign_table'] ?? null),
                'foreign_columns' => explode(',', self::text($row['foreign_columns'] ?? null)),
                'on_update' => null,
                'on_delete' => null,
            ];
        }

        return $keys;
    }

    private static function grammar(Connection $ro): Grammar
    {
        $ro->getSchemaBuilder(); // Sets the schema grammar, which is null before.

        return $ro->getSchemaGrammar();
    }

    /**
     * The grammar puts a name between quotes without escaping it, so a ' in a name broke the query, and a
     * crafted name could read another table's catalog rows. The connection escapes it for its own driver;
     * the grammar adds the outer quotes. The same three lines as SchemaSnapshot::escaped() (01-11).
     */
    private static function escaped(Connection $connection, string $name): string
    {
        return substr($connection->escape($name), 1, -1);
    }

    /** A catalog name as a quoted identifier: no table prefix, the quote character doubled. */
    private static function identifier(Connection $ro, string $name): string
    {
        return $ro->getDriverName() === 'pgsql'
            ? '"'.str_replace('"', '""', $name).'"'
            : '`'.str_replace('`', '``', $name).'`';
    }

    /**
     * The rows of a catalog query as arrays, the shape Laravel's processors take. Never the read PDO.
     *
     * @param  list<string>  $bindings
     * @return list<array<string, mixed>>
     */
    private static function select(Connection $ro, string $sql, array $bindings = []): array
    {
        $rows = [];

        foreach ($ro->selectFromWriteConnection($sql, $bindings) as $row) {
            $values = [];

            foreach ((array) $row as $key => $value) {
                $values[(string) $key] = $value;
            }

            $rows[] = $values;
        }

        return $rows;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : (is_int($value) ? (string) $value : '');
    }
}
