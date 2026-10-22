<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Spec 5.2, 01-14 Part D: the database refused agent SQL. ReadOnlySession::run() throws one of the four
 * final subclasses, with the PDO exception as previous; 01-20 turns each one into an envelope result.
 *
 * The message is the database's own message (PDO errorInfo[2]), never QueryException::getMessage():
 * that one also holds the agent SQL and the connection name.
 */
abstract class QueryRejected extends RuntimeException
{
    public function __construct(
        // The SQLSTATE: '42601', '25006', 'HY000', ...
        public readonly string $sqlState,
        // MySQL and MariaDB: the error number (1142, 3024, ...). Null on PostgreSQL, whose errorInfo[1] is always 7.
        public readonly ?int $driverCode,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
