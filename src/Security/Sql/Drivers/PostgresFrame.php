<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Sql\Drivers;

use Illuminate\Database\Connection;
use PDOException;
use SinaSaeedi\RuntimeMcp\Security\Sql\DatabaseError;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\QueryFailed;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\QueryPermissionDenied;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\QueryRejected;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\QueryTimedOut;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\ReadOnlyViolation;

/**
 * Spec 5.2 on PostgreSQL: BEGIN, SET TRANSACTION READ ONLY as the first statement, SET LOCAL
 * statement_timeout; after the rollback, DISCARD ALL (it also releases a session advisory lock).
 */
final class PostgresFrame implements SessionFrame
{
    public function open(Connection $connection, int $timeoutMs): void
    {
        $connection->beginTransaction();
        $connection->statement('SET TRANSACTION READ ONLY');
        // After BEGIN: outside a transaction, SET LOCAL is a warning and does nothing.
        $this->setTimeout($connection, $timeoutMs);
    }

    public function outerTransactionIsReadOnly(Connection $connection): bool
    {
        $row = $connection->selectOne('SHOW transaction_read_only');

        return is_object($row) && ($row->transaction_read_only ?? null) === 'on';
    }

    public function setTimeout(Connection $connection, int $timeoutMs): void
    {
        // SET takes no bind parameter (42601 at "$1"), so the value is a validated int, never a string.
        // SET LOCAL ends with the transaction, and a ROLLBACK TO SAVEPOINT undoes it.
        $connection->statement('SET LOCAL statement_timeout = '.$timeoutMs);
    }

    public function clearSession(Connection $connection): void
    {
        // Cannot run inside a transaction block: ReadOnlySession and SessionLedger roll back first.
        $connection->statement('DISCARD ALL');
    }

    public function translate(PDOException $e, int $timeoutMs): QueryRejected
    {
        $error = DatabaseError::of($e);

        // By SQLSTATE: PostgreSQL's errorInfo[1] is always 7, so no driver code is kept.
        return match ($error->sqlState) {
            '57014' => new QueryTimedOut($timeoutMs, $error->sqlState, null, $error->message, $e),
            '25006' => new ReadOnlyViolation($error->sqlState, null, $error->message, $e),
            '42501' => new QueryPermissionDenied($error->sqlState, null, $error->message, $e),
            default => new QueryFailed($error->sqlState, null, $error->message, $e),
        };
    }
}
