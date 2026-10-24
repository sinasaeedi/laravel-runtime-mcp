<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Sql\Limits;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use PDOException;
use SinaSaeedi\RuntimeMcp\Security\Sql\DatabaseError;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\QueryNotLimitable;
use SinaSaeedi\RuntimeMcp\Security\Sql\LimitStrategy;

/**
 * Spec 5.2 on MySQL and MariaDB (Sina, 2026-10-02): wrap or refuse, never the bare statement. A top-level
 * MAX_EXECUTION_TIME optimizer hint, MariaDB's SET STATEMENT … FOR and an executing EXPLAIN ANALYZE or
 * ANALYZE would escape the timeout; inside the derived table they are ignored or a syntax error.
 */
final class MySqlLimiter implements Limiter
{
    // 1064 any syntax error (also EXPLAIN, SHOW, SET STATEMENT, ANALYZE, and TABLE and INTO on MariaDB),
    // 1060 duplicate column name, 3954 INTO inside a subquery (MySQL). Returned by MySQL 8.4, MariaDB 11.4 and 10.11.
    private const NOT_LIMITABLE = [1064, 1060, 3954];

    /** @param  (Closure(LimitStrategy): void)|null  $onAttempt  called before the attempt (tests); null in production */
    public function __construct(private readonly ?Closure $onAttempt = null) {}

    public function execute(Connection $connection, string $sql, int $limit): array
    {
        if ($this->onAttempt !== null) {
            ($this->onAttempt)(LimitStrategy::Wrapped);
        }

        try {
            $statement = $connection->getPdo()->prepare(sprintf(self::WRAP, $sql, $limit));
            $statement->execute();
        } catch (PDOException $e) {
            $error = DatabaseError::of($e);

            if (! in_array($error->driverCode, self::NOT_LIMITABLE, true)) {
                throw $e;
            }

            throw new QueryNotLimitable($error->sqlState, $error->driverCode, self::message($connection, $error->message), $e);
        }

        return [$statement, LimitStrategy::Wrapped];
    }

    /** Ends with the database's own message (Sina, 2026-10-22): 1064 is also a plain typo. */
    private static function message(Connection $connection, string $said): string
    {
        $database = $connection instanceof MySqlConnection && $connection->isMaria() ? 'MariaDB' : 'MySQL';

        return "{$database} could not run this query inside the row limit, so it was not run. On {$database}, "
            .'db_query runs SELECT-style queries only (no EXPLAIN or SHOW). If the query returns two columns with '
            ."the same name, give each a unique alias, for example `orders.id AS order_id`. The database said: {$said}";
    }
}
