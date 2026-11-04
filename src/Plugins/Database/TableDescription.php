<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins\Database;

/**
 * Spec 6.3: one table as the read-only role sees it (01-19). Built by ReadableSchema::describe(), after
 * its filters: on MySQL and MariaDB no index or foreign key here names a column that the role cannot see,
 * and on every driver no foreign key names a denied table.
 */
final readonly class TableDescription
{
    /**
     * @param  'table'|'view'  $type
     * @param  list<array{name: string, type: string, nullable: bool, default: mixed, readable: bool}>  $columns
     * @param  list<array{name: string, columns: list<string>, unique: bool, primary: bool}>  $indexes
     * @param  list<array{name: string|null, columns: list<string>, foreign_schema: string|null, foreign_table: string, foreign_columns: list<string>, on_update: string|null, on_delete: string|null}>  $foreignKeys
     */
    public function __construct(
        public string $schema,
        public string $name,
        public string $type,
        public array $columns,
        public array $indexes,
        public array $foreignKeys,
    ) {}

    /** @return array{table: string, schema: string, type: 'table'|'view', columns: list<array<string, mixed>>, indexes: list<array<string, mixed>>, foreign_keys: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return [
            'table' => $this->name,
            'schema' => $this->schema,
            'type' => $this->type,
            'columns' => $this->columns,
            'indexes' => $this->indexes,
            'foreign_keys' => $this->foreignKeys,
        ];
    }
}
