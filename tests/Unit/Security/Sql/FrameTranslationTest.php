<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use SinaSaeedi\RuntimeMcp\Security\Sql\DatabaseError;
use SinaSaeedi\RuntimeMcp\Security\Sql\Drivers\MariaDbFrame;
use SinaSaeedi\RuntimeMcp\Security\Sql\Drivers\MySqlFrame;
use SinaSaeedi\RuntimeMcp\Security\Sql\Drivers\PostgresFrame;
use SinaSaeedi\RuntimeMcp\Security\Sql\Drivers\SessionFrame;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\QueryFailed;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\QueryPermissionDenied;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\QueryTimedOut;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\ReadOnlyViolation;

/*
 * Spec 5.2, 01-14 Part D: each frame maps a database error to one class, by SQLSTATE on PostgreSQL and
 * by error number on MySQL and MariaDB, and keeps the database's own message, never the SQL. The codes
 * are the ones the audit spike saw (2026-10-21); the integration tests prove them on the real servers.
 */

/** A QueryException as Laravel throws it: the SQL in its message, the PDO error under it. */
function translationError(string $sqlState, int $driverCode, string $message): QueryException
{
    $pdo = new PDOException("SQLSTATE[{$sqlState}]: {$message}");
    $pdo->errorInfo = [$sqlState, $driverCode, $message];

    return new QueryException('runtime_mcp_ro', 'SELECT secret_marker FROM orders', [], $pdo);
}

/** @return array{0: class-string, 1: string, 2: ?int, 3: string} */
function translated(SessionFrame $frame, QueryException $e): array
{
    $rejected = $frame->translate($e, 750);

    return [$rejected::class, $rejected->sqlState, $rejected->driverCode, $rejected->getMessage()];
}

it('X1 maps PostgreSQL by SQLSTATE, with no driver code', function (string $sqlState, string $class) {
    expect(translated(new PostgresFrame, translationError($sqlState, 7, 'ERROR:  the database said no')))
        ->toBe([$class, $sqlState, null, 'ERROR:  the database said no']);
})->with([
    'timeout' => ['57014', QueryTimedOut::class],
    'read-only transaction' => ['25006', ReadOnlyViolation::class],
    'privilege' => ['42501', QueryPermissionDenied::class],
    'stacked statements' => ['42601', QueryFailed::class],
    'lost connection' => ['HY000', QueryFailed::class],
]);

it('X2 maps MySQL by error number, and keeps the number', function (int $code, string $sqlState, string $class) {
    expect(translated(new MySqlFrame, translationError($sqlState, $code, 'the database said no')))
        ->toBe([$class, $sqlState, $code, 'the database said no']);
})->with([
    'timeout' => [3024, 'HY000', QueryTimedOut::class],
    'read-only transaction' => [1792, '25006', ReadOnlyViolation::class],
    'table privilege' => [1142, '42000', QueryPermissionDenied::class],
    'column privilege' => [1143, '42000', QueryPermissionDenied::class],
    'database privilege (Sina, 2026-10-21)' => [1044, '42000', QueryPermissionDenied::class],
    'routine privilege (01-14 re-review)' => [1370, '42000', QueryPermissionDenied::class],
    'syntax' => [1064, '42000', QueryFailed::class],
    'MariaDB timeout is not MySQL timeout' => [1969, '70100', QueryFailed::class],
    'unknown variable' => [1193, 'HY000', QueryFailed::class],
    'gone away' => [2006, 'HY000', QueryFailed::class],
]);

it('X3 maps MariaDB as MySQL, but with its own timeout number', function (int $code, string $sqlState, string $class) {
    expect(translated(new MariaDbFrame, translationError($sqlState, $code, 'the database said no'))[0])->toBe($class);
})->with([
    'timeout' => [1969, '70100', QueryTimedOut::class],
    'MySQL timeout is not MariaDB timeout' => [3024, 'HY000', QueryFailed::class],
    'read-only transaction' => [1792, '25006', ReadOnlyViolation::class],
    'table privilege' => [1142, '42000', QueryPermissionDenied::class],
    'database privilege' => [1044, '42000', QueryPermissionDenied::class],
    'routine privilege' => [1370, '42000', QueryPermissionDenied::class],
    'syntax' => [1064, '42000', QueryFailed::class],
]);

it('X4 keeps the timeout and the PDO exception chain, and never the SQL in the message', function () {
    $e = translationError('57014', 7, 'ERROR:  canceling statement due to statement timeout');
    $rejected = (new PostgresFrame)->translate($e, 750);

    expect($rejected)->toBeInstanceOf(QueryTimedOut::class)
        ->and($rejected instanceof QueryTimedOut ? $rejected->timeoutMs : null)->toBe(750)
        ->and($rejected->getPrevious())->toBe($e)
        ->and(str_contains($e->getMessage(), 'secret_marker'))->toBeTrue('Laravel puts the SQL in its message.')
        ->and(str_contains($rejected->getMessage(), 'secret_marker'))->toBeFalse('The package message must not hold the SQL.')
        ->and(str_contains($rejected->getMessage(), 'Connection:'))->toBeFalse();
});

it('X5 falls back to the SQLSTATE in the code and the message under the QueryException, when errorInfo is missing', function () {
    $pdo = new PDOException('SQLSTATE[08006]: server closed the connection unexpectedly');
    $e = new QueryException('runtime_mcp_ro', 'SELECT secret_marker', [], $pdo);
    $error = DatabaseError::of($e);

    expect([$error->sqlState, $error->driverCode, $error->message])
        ->toBe(['HY000', null, 'SQLSTATE[08006]: server closed the connection unexpectedly']);

    $bare = new PDOException('There is no active transaction');
    $bare->errorInfo = null;

    expect(DatabaseError::of($bare)->message)->toBe('There is no active transaction');
});

it('X6 reads a string SQLSTATE from the exception code when errorInfo has none', function () {
    $pdo = new class('lost') extends PDOException
    {
        public function __construct(string $message)
        {
            parent::__construct($message);
            $this->code = '08006';
        }
    };

    expect(DatabaseError::of($pdo)->sqlState)->toBe('08006');
});
