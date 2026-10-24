<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Sql;

use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use PDO;
use PDOStatement;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\NotInReadOnlyTransaction;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\QueryFailed;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\QueryRejected;
use SinaSaeedi\RuntimeMcp\Security\Sql\Limits\MySqlLimiter;
use SinaSaeedi\RuntimeMcp\Security\Sql\Limits\PostgresLimiter;
use SinaSaeedi\RuntimeMcp\Security\UnsupportedDriver;

/**
 * Spec 5.2, rule 8, 01-15: runs agent SQL inside ReadOnlySession (01-14) with the row limit inside the
 * database. PHP never receives more than max_rows + 1 rows. No SQL is parsed (rule 2): the only change
 * to the text is the trim of trailing blanks and semicolons.
 *
 * Not bound in the container, like ReadOnlySession: each make() gets the request's scoped SessionLedger.
 */
final class QueryRunner
{
    public const EMPTY = 'The query is empty.';

    public const BINARY = '[binary value]';

    public function __construct(
        private readonly ReadOnlySession $session,
        private readonly MaxRowsSetting $maxRows,
    ) {}

    /**
     * @param  int|null  $maxRows  the agent's limit; never above database.max_rows
     *
     * @throws QueryRejected one of 01-14's four, or QueryNotLimitable (MySQL, MariaDB)
     * @throws NotInReadOnlyTransaction
     * @throws UnsupportedDriver
     */
    public function run(string $sql, ?int $maxRows = null): QueryResult
    {
        $ceiling = $this->maxRows->rows();
        $maxRows = max(1, min($maxRows ?? $ceiling, $ceiling));
        // Cannot change the meaning of one statement, and lets "SELECT …;" wrap.
        $sql = rtrim($sql, " \t\n\r\0\x0B;");

        if ($sql === '') {
            // PDO::prepare('') throws a ValueError, which no frame translates.
            throw new QueryFailed('42601', null, self::EMPTY);
        }

        return $this->session->run(function (Connection $connection) use ($sql, $maxRows): QueryResult {
            // Frames::for() already refused any other driver.
            $limiter = $connection->getDriverName() === 'pgsql' ? new PostgresLimiter : new MySqlLimiter;
            [$statement, $strategy] = $limiter->execute($connection, $sql, $maxRows + 1);

            return self::read($statement, $strategy, $maxRows, $connection instanceof MySqlConnection && $connection->isMaria());
        });
    }

    private static function read(PDOStatement $statement, LimitStrategy $strategy, int $maxRows, bool $maria): QueryResult
    {
        // From the statement, not the rows: an empty result still has its columns, and a duplicate name stays.
        $columns = [];

        for ($i = 0; $i < $statement->columnCount(); $i++) {
            $meta = $statement->getColumnMeta($i);
            $columns[] = $meta === false ? '' : $meta['name'];
        }

        // At most $maxRows + 1: the database limited them.
        $rows = [];

        while (is_array($row = $statement->fetch(PDO::FETCH_NUM))) {
            $rows[] = array_map(self::value(...), $row);
        }

        $hasMore = count($rows) > $maxRows;
        $rows = array_slice($rows, 0, $maxRows);

        return new QueryResult($columns, $rows, count($rows), $hasMore, $strategy, $maria);
    }

    /** JSON safety only; every other value stays as PDO returned it. */
    private static function value(mixed $value): mixed
    {
        // PostgreSQL bytea is a stream. Not read: binary data is useless to an agent.
        if (is_resource($value)) {
            return self::BINARY;
        }

        // PostgreSQL NaN and ±Infinity: json_encode() refuses them (Sina, 2026-10-22).
        if (is_float($value) && ! is_finite($value)) {
            return is_nan($value) ? 'NaN' : ($value > 0 ? 'Infinity' : '-Infinity');
        }

        return $value;
    }
}
