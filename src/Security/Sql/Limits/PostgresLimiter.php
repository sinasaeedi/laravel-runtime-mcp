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
                return [$this->attempt($pdo, $strategy, $sql, $limit), $strategy];
            } catch (PDOException $e) {
                if (DatabaseError::of($e)->sqlState !== self::SYNTAX_ERROR) {
                    // ReadOnlySession rolls back, resets, then translates this same exception.
                    throw $e;
                }

                $connection->rollBack($level);
            }
        }

        // A statement that a cursor cannot hold: in practice EXPLAIN and SHOW, whose output is small.
        return [$this->attempt($pdo, LimitStrategy::Bare, $sql, $limit), LimitStrategy::Bare];
    }

    private function attempt(PDO $pdo, LimitStrategy $strategy, string $sql, int $limit): PDOStatement
    {
        if ($this->onAttempt !== null) {
            ($this->onAttempt)($strategy);
        }

        if ($strategy === LimitStrategy::Cursor) {
            // A native prepared statement too: the extended protocol accepts DECLARE (checked on PostgreSQL 17).
            $pdo->prepare('DECLARE '.self::CURSOR." NO SCROLL CURSOR FOR\n{$sql}")->execute();
        }

        $statement = $pdo->prepare(match ($strategy) {
            LimitStrategy::Wrapped => sprintf(self::WRAP, $sql, $limit),
            LimitStrategy::Cursor => 'FETCH FORWARD '.$limit.' FROM '.self::CURSOR,
            LimitStrategy::Bare => $sql,
        });
        $statement->execute();

        return $statement;
    }
}
