<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Grants;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Grammars\Grammar;
use SinaSaeedi\RuntimeMcp\Security\UnsupportedDriver;

/**
 * Spec 5.1, 7.1: the base tables and columns of the app schemas, read once, for RoleSqlGenerator.
 * Views and PostgreSQL partitions are left out: neither ever gets a grant.
 */
final class SchemaSnapshot
{
    private const DRIVERS = ['pgsql', 'mysql', 'mariadb'];

    /** @param list<TableSnapshot> $tables sorted by schema, then name (byte order) */
    public function __construct(
        public readonly string $driver,    // pgsql | mysql | mariadb
        public readonly string $database,  // $connection->getDatabaseName()
        public readonly string $prefix,    // $connection->getTablePrefix()
        public readonly array $tables,
    ) {}

    /** Reads the base tables of the app schemas. Any driver but pgsql, mysql, mariadb → UnsupportedDriver. */
    public static function read(Connection $connection): self
    {
        $driver = $connection->getDriverName();

        if (! in_array($driver, self::DRIVERS, true)) {
            throw new UnsupportedDriver($driver);
        }

        $schemas = self::appSchemas($connection);

        // Never getTables([]) or getTables(null): both mean "every schema".
        if ($schemas === []) {
            return new self($driver, $connection->getDatabaseName(), $connection->getTablePrefix(), []);
        }

        $partitions = $driver === 'pgsql' ? self::partitions($connection, $schemas) : [];
        $grammar = $connection->getSchemaGrammar();
        $tables = [];

        foreach ($connection->getSchemaBuilder()->getTables($schemas) as $row) {
            $schema = $row['schema'] ?? $connection->getDatabaseName();

            // A read through the parent uses the parent's grants only, so a partition needs none.
            if (isset($partitions[$schema."\0".$row['name']])) {
                continue;
            }

            $tables[] = new TableSnapshot($schema, $row['name'], self::columns($connection, $grammar, $schema, $row['name']));
        }

        // In PHP, not by the database collation, so the SQL is stable and diff-able.
        usort($tables, fn (TableSnapshot $a, TableSnapshot $b): int => strcmp($a->schema, $b->schema) ?: strcmp($a->name, $b->name));

        return new self($driver, $connection->getDatabaseName(), $connection->getTablePrefix(), $tables);
    }

    /** @return list<string> PostgreSQL: the search_path; MySQL and MariaDB: the database. 01-13 and 01-19 reuse it. */
    public static function appSchemas(Connection $connection): array
    {
        // getSchemaBuilder() first: it sets the schema grammar, which is null before.
        $listing = $connection->getSchemaBuilder()->getCurrentSchemaListing() ?? [];

        // Only non-empty strings: PostgreSQL's "$user" becomes the username config, which is not typed.
        $schemas = array_filter(array_map(self::text(...), $listing), fn (string $schema): bool => $schema !== '');

        // getTables() leaves out the PostgreSQL system schemas only when it gets no list. A search path
        // that names pg_catalog would otherwise grant pg_authid and pg_statistic. PostgreSQL reserves the
        // pg_ prefix, so no app schema starts with it.
        if ($connection->getDriverName() === 'pgsql') {
            $schemas = array_filter($schemas, fn (string $schema): bool => $schema !== 'information_schema' && ! str_starts_with($schema, 'pg_'));
        }

        return array_values($schemas);
    }

    /**
     * The column names in ordinal order. Not Builder::getColumns(): it adds the table prefix to a name
     * that getTables() already returns with the prefix, so a prefixed app would get no columns and no
     * grants. Both grammars select the name "as name" and order by ordinal position; PostgreSQL leaves
     * dropped columns out.
     *
     * @return list<string>
     */
    private static function columns(Connection $connection, Grammar $grammar, string $schema, string $table): array
    {
        $columns = [];
        $sql = $grammar->compileColumns(self::escaped($connection, $schema), self::escaped($connection, $table));

        foreach ($connection->selectFromWriteConnection($sql) as $row) {
            $name = self::text(((array) $row)['name'] ?? null);

            // A row without a name cannot be granted: leaving it out only denies more.
            if ($name !== '') {
                $columns[] = $name;
            }
        }

        return $columns;
    }

    /**
     * getTables() lists a PostgreSQL partition as a normal table (relkind 'r').
     *
     * @param  list<string>  $schemas
     * @return array<string, true> "schema\0name" keys
     */
    private static function partitions(Connection $connection, array $schemas): array
    {
        $rows = $connection->selectFromWriteConnection(
            'select n.nspname as schema, c.relname as name '
            .'from pg_class c join pg_namespace n on n.oid = c.relnamespace '
            .'where c.relispartition and n.nspname in ('.implode(', ', array_fill(0, count($schemas), '?')).')',
            $schemas,
        );

        $partitions = [];

        foreach ($rows as $row) {
            $row = (array) $row;
            $partitions[self::text($row['schema'] ?? null)."\0".self::text($row['name'] ?? null)] = true;
        }

        return $partitions;
    }

    /**
     * compileColumns() puts each name between quotes without escaping it, so a ' in a table name broke
     * the query, and a crafted name could read the columns of another table. The connection escapes it
     * for its own driver; the grammar adds the outer quotes.
     */
    private static function escaped(Connection $connection, string $name): string
    {
        return substr($connection->escape($name), 1, -1);
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
