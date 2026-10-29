<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Sql\Limits;

use Closure;
use Illuminate\Database\Connection;
use PDO;
use PDOException;
use PDOStatement;
use SinaSaeedi\RuntimeMcp\Security\Sql\DatabaseError;
use SinaSaeedi\RuntimeMcp\Security\Sql\LimitStrategy;

/**
 * Spec 5.2 on PostgreSQL (Sina, 2026-10-02): wrap, else a cursor, else the bare statement. Only a syntax
 * error (42601) moves to the next step, so a timeout or a permission error never runs twice. Each try
 * runs in its own savepoint: any error aborts a PostgreSQL transaction.
 *
 * Only the DECLARE can send a text to the bare step. A query can raise 42601 as it runs (query_to_xml(),
 * to_tsquery()), and it can see which form runs it (current_query()), so a 42601 from the FETCH is final.
 */
final class PostgresLimiter implements Limiter
{
    private const SYNTAX_ERROR = '42601';

    // One cursor per call. It dies with the savepoint's or the transaction's rollback.
    private const CURSOR = 'runtime_mcp_c';

    /** @param  (Closure(LimitStrategy): void)|null  $onAttempt  called before each attempt (tests); null in production */
    public function __construct(private readonly ?Closure $onAttempt = null) {}

    public function execute(Connection $connection, string $sql, int $limit): array
    {
        $pdo = $connection->getPdo();

        foreach ([LimitStrategy::Wrapped, LimitStrategy::Cursor] as $strategy) {
            $level = $connection->transactionLevel();
            $connection->beginTransaction(); // a SAVEPOINT

            try {
                $statement = $this->attempt($pdo, $strategy, $sql, $limit);
            } catch (PDOException $e) {
                if (DatabaseError::of($e)->sqlState !== self::SYNTAX_ERROR) {
                    // ReadOnlySession rolls back, resets, then translates this same exception.
                    throw $e;
                }

                $connection->rollBack($level);

                continue;
            }

            if ($strategy === LimitStrategy::Cursor) {
                // Outside the try: the DECLARE took the text, so any error from here on is the query's own.
                $statement = self::prepared($pdo, 'FETCH FORWARD '.$limit.' FROM '.self::CURSOR);
            }

            return [$statement, $strategy];
        }

        // A statement that a cursor cannot hold: in practice EXPLAIN and SHOW, whose output is small.
        return [$this->attempt($pdo, LimitStrategy::Bare, $sql, $limit), LimitStrategy::Bare];
    }

    /** For the cursor, only the DECLARE: execute() runs the FETCH. */
    private function attempt(PDO $pdo, LimitStrategy $strategy, string $sql, int $limit): PDOStatement
    {
        if ($this->onAttempt !== null) {
            ($this->onAttempt)($strategy);
        }

        return self::prepared($pdo, match ($strategy) {
            LimitStrategy::Wrapped => sprintf(self::WRAP, $sql, $limit),
            // A native prepared statement too: the extended protocol accepts DECLARE (checked on PostgreSQL 17).
            LimitStrategy::Cursor => 'DECLARE '.self::CURSOR." NO SCROLL CURSOR FOR\n{$sql}",
            LimitStrategy::Bare => $sql,
        });
    }

    private static function prepared(PDO $pdo, string $sql): PDOStatement
    {
        $statement = $pdo->prepare($sql);
        $statement->execute();

        return $statement;
    }
}
