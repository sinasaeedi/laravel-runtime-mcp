<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Sql;

use Illuminate\Database\QueryException;
use PDOException;

/**
 * Spec 5.2, 01-14 Part D: the three facts of a PDO error that the frames map, read from errorInfo.
 * QueryException extends PDOException and copies errorInfo; a PDOException from PDO itself (a
 * beginTransaction() or a savepoint) has it too, or null when there was no server answer.
 */
final readonly class DatabaseError
{
    public function __construct(
        public string $sqlState,
        public ?int $driverCode,
        public string $message,
    ) {}

    public static function of(PDOException $e): self
    {
        $info = $e->errorInfo ?? [];
        $code = $e->getCode();
        $sqlState = $info[0] ?? null;
        $driverCode = $info[1] ?? null;
        $message = $info[2] ?? null;

        return new self(
            is_string($sqlState) && $sqlState !== '' ? $sqlState : (is_string($code) && $code !== '' ? $code : 'HY000'),
            is_int($driverCode) ? $driverCode : null,
            is_string($message) && $message !== '' ? $message : self::fallbackMessage($e),
        );
    }

    /** Never QueryException::getMessage(): it holds the SQL. The PDO exception under it does not. */
    private static function fallbackMessage(PDOException $e): string
    {
        if ($e instanceof QueryException) {
            return $e->getPrevious()?->getMessage() ?? 'The database refused the query.';
        }

        return $e->getMessage();
    }
}
