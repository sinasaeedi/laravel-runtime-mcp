<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Sql\Limits;

use Illuminate\Database\Connection;
use PDOStatement;
use SinaSaeedi\RuntimeMcp\Security\Sql\LimitStrategy;

/** Spec 5.2, rule 8, 01-15: runs agent SQL so that the database returns at most $limit rows. */
interface Limiter
{
    // The agent SQL on its own lines, so a trailing -- comment cannot comment out the closing parenthesis.
    public const WRAP = "SELECT * FROM (\n%s\n) AS runtime_mcp_q LIMIT %d";

    /**
     * Runs inside ReadOnlySession::run()'s $work. Returns the executed statement, whose rows the database
     * already limited to $limit, and the strategy that worked. A database error leaves as the raw PDOException.
     *
     * @return array{PDOStatement, LimitStrategy}
     */
    public function execute(Connection $connection, string $sql, int $limit): array;
}
