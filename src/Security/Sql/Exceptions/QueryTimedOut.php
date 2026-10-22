<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions;

use Throwable;

/**
 * Spec 5.2, 01-14 Part D: the statement timeout stopped the SQL. PostgreSQL 57014, MySQL 3024, MariaDB
 * 1969. MySQL's SLEEP() is the exception: it returns 1 when the timeout stops it, and raises nothing.
 */
final class QueryTimedOut extends QueryRejected
{
    public function __construct(
        // The timeout in force, for 01-20's note ("stopped by the 5000 ms statement timeout").
        public readonly int $timeoutMs,
        string $sqlState,
        ?int $driverCode,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($sqlState, $driverCode, $message, $previous);
    }
}
