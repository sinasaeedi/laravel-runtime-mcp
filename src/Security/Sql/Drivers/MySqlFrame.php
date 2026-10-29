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
 * Spec 5.2 on MySQL: SET SESSION max_execution_time, SET TRANSACTION READ ONLY (the next transaction
 * only), then beginTransaction(), which sends START TRANSACTION, now read-only. After the rollback the
 * session ends (disconnect), which also drops GET_LOCK() locks and user variables.
 *
 * Not final: MariaDbFrame changes only the timeout statement and its error code.
 */
class MySqlFrame implements SessionFrame
{
    private const READ_ONLY_CODE = 1792;

    // Table, column and database privilege refused (1044: Sina, 2026-10-21, for CREATE TEMPORARY TABLE),
    // and EXECUTE on a routine (1370: 01-14 re-review, 2026-10-29). PostgreSQL gives 42501 for each.
    private const PERMISSION_CODES = [1142, 1143, 1044, 1370];

    public function open(Connection $connection, int $timeoutMs): void
    {
        // Session-wide: the disconnect after the call ends it. Not a raw START TRANSACTION READ ONLY:
        // Laravel's transaction level would stay 0, and its own beginTransaction() would then throw.
        $this->setTimeout($connection, $timeoutMs);
        $connection->statement('SET TRANSACTION READ ONLY');
        $connection->beginTransaction();
    }

    public function outerTransactionIsReadOnly(Connection $connection): ?bool
    {
        // MySQL has no flag for the open transaction: Case B trusts 01-16's mark in the SessionLedger.
        return null;
    }

    public function setTimeout(Connection $connection, int $timeoutMs): void
    {
        $connection->statement($this->timeoutStatement($timeoutMs));
    }

    public function clearSession(Connection $connection): void
    {
        // The next ReadOnlySession call reconnects (reconnectIfMissingConnection()).
        $connection->disconnect();
    }

    public function translate(PDOException $e, int $timeoutMs): QueryRejected
    {
        $error = DatabaseError::of($e);
        $code = $error->driverCode;

        // By error number: 3024 has SQLSTATE HY000, like many others.
        return match (true) {
            $code === $this->timeoutCode() => new QueryTimedOut($timeoutMs, $error->sqlState, $code, $error->message, $e),
            $code === self::READ_ONLY_CODE => new ReadOnlyViolation($error->sqlState, $code, $error->message, $e),
            in_array($code, self::PERMISSION_CODES, true) => new QueryPermissionDenied($error->sqlState, $code, $error->message, $e),
            default => new QueryFailed($error->sqlState, $code, $error->message, $e),
        };
    }

    /** MySQL: milliseconds. It applies only to read-only SELECT statements, and a top-level hint overrides it (01-15 wraps every query). */
    protected function timeoutStatement(int $timeoutMs): string
    {
        return 'SET SESSION max_execution_time = '.$timeoutMs;
    }

    /** ER_QUERY_TIMEOUT. */
    protected function timeoutCode(): int
    {
        return 3024;
    }
}
