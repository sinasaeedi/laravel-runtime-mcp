<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\ReadOnly;

use Illuminate\Cache\DatabaseLock;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Database\QueryException;
use SinaSaeedi\RuntimeMcp\Security\Sql\DatabaseError;
use Throwable;

/**
 * Spec 5.9, 01-16 Part C: the agent's note when app code inside a read tool hit the read-only
 * transaction. RunTool asks before its generic note; null means "not a read-only error".
 *
 * It reads error codes and the exception trace, never the SQL text (Sina, Q2): so rule 2 does not
 * come up, and a table prefix, a lock table or a second database store changes nothing.
 */
final class WriteAttemptExplainer
{
    public const WRITE = 'The tool tried to write to the database, and read tools run in a read-only transaction. The write was blocked and nothing was changed.';

    public const CACHE = 'A cache write with the `database` cache driver failed: read tools run in a read-only transaction. Use another cache store for code that tools call.';

    public const ABORTED = 'An earlier database error in this tool call was caught by app code, so PostgreSQL refused the rest of the read-only transaction. A blocked write is the usual cause. Nothing was changed.';

    public function note(Throwable $e): ?string
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if (! $current instanceof QueryException) {
                continue;
            }

            $kind = self::kind(DatabaseError::of($current));

            if ($kind === null) {
                continue;
            }

            if (self::fromDatabaseCache($current)) {
                return self::CACHE;
            }

            return $kind;
        }

        return null;
    }

    /**
     * PostgreSQL 25006 and MySQL/MariaDB 1792 (SQLSTATE 25006): the read-only transaction refused a
     * write. SQLite 8 (SQLITE_READONLY): query_only refused it. PostgreSQL 25P02: the transaction was
     * aborted by an earlier error that app code caught (DatabaseLock::acquire() catches its insert).
     */
    private static function kind(DatabaseError $error): ?string
    {
        return match (true) {
            $error->sqlState === '25006' => self::WRITE,
            $error->sqlState === 'HY000' && $error->driverCode === 8 => self::WRITE,
            $error->sqlState === '25P02' => self::ABORTED,
            default => null,
        };
    }

    private static function fromDatabaseCache(QueryException $e): bool
    {
        foreach ($e->getTrace() as $frame) {
            $class = $frame['class'] ?? null;

            if ($class !== null && (is_a($class, DatabaseStore::class, true) || is_a($class, DatabaseLock::class, true))) {
                return true;
            }
        }

        return false;
    }
}
