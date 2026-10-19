<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Grants;

/** One base table of a SchemaSnapshot. */
final class TableSnapshot
{
    /** @param list<string> $columns in ordinal (table) order */
    public function __construct(
        public readonly string $schema,
        public readonly string $name,   // the real name, with the prefix if the table has one
        public readonly array $columns,
    ) {}
}
