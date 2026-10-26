<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use SinaSaeedi\RuntimeMcp\Security\ReadOnly\WriteAttemptExplainer;

/*
 * Spec 5.9, 01-16 Part C: the codes the four servers and SQLite give (probed on 2026-10-24), the
 * previous chain, and errors that are not a read-only refusal. The cache trace is proved for real by
 * RF4, RP3 and RM3; QueryException is built here outside any cache store, so its trace has none.
 */

function writeExplainerQuery(string $sqlState, ?int $driverCode, string $message): QueryException
{
    $pdo = new PDOException('SQLSTATE['.$sqlState.']: '.$message);
    $pdo->errorInfo = [$sqlState, $driverCode, $message];

    return new QueryException('testing', 'insert into "ro_orders" ("n") values (?)', ['x'], $pdo);
}

it('RU1 names a write that the read-only transaction refused, on every server', function (string $sqlState, int $driverCode, string $message) {
    expect((new WriteAttemptExplainer)->note(writeExplainerQuery($sqlState, $driverCode, $message)))->toBe(WriteAttemptExplainer::WRITE);
})->with([
    'PostgreSQL 25006' => ['25006', 7, 'ERROR:  cannot execute INSERT in a read-only transaction'],
    'MySQL and MariaDB 1792' => ['25006', 1792, 'Cannot execute statement in a READ ONLY transaction.'],
    'SQLite 8' => ['HY000', 8, 'attempt to write a readonly database'],
]);

it('RU2 gives the aborted note for PostgreSQL 25P02', function () {
    expect((new WriteAttemptExplainer)->note(writeExplainerQuery('25P02', 7, 'ERROR:  current transaction is aborted')))->toBe(WriteAttemptExplainer::ABORTED);
});

it('RU3 finds the refusal in the previous chain of an app exception', function () {
    $e = new RuntimeException('The order could not be saved.', 0, writeExplainerQuery('25006', 1792, 'read only'));

    expect((new WriteAttemptExplainer)->note($e))->toBe(WriteAttemptExplainer::WRITE);
});

it('RU4 says nothing for another database error or a plain exception (the generic note stays)', function (Throwable $e) {
    expect((new WriteAttemptExplainer)->note($e))->toBeNull();
})->with([
    'a syntax error' => [fn (): Throwable => writeExplainerQuery('42601', 7, 'ERROR:  syntax error')],
    'SQLite busy (5)' => [fn (): Throwable => writeExplainerQuery('HY000', 5, 'database is locked')],
    'MySQL 1146 with HY000' => [fn (): Throwable => writeExplainerQuery('HY000', 1146, 'no such table')],
    'a plain exception' => [fn (): Throwable => new RuntimeException('no database here')],
]);
