<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins\Database;

use SinaSaeedi\RuntimeMcp\Plugins\AbstractPlugin;

/** Base plugin (spec 4.2): no package needed, on by default. Tools: db_schema (01-19), db_query (01-20). */
final class DatabasePlugin extends AbstractPlugin
{
    public function name(): string
    {
        return 'database';
    }

    public function description(): string
    {
        return 'The database schema and read-only SQL on the read-only connection.';
    }

    public function enabledByDefault(): bool
    {
        return true;
    }

    public function toolPrefix(): string
    {
        return 'db_';
    }

    public function requiresReadOnlyConnection(): bool
    {
        return true;
    }
}
