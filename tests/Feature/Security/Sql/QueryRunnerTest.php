<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\QueryFailed;
use SinaSaeedi\RuntimeMcp\Security\Sql\QueryRunner;
use SinaSaeedi\RuntimeMcp\Security\UnsupportedDriver;

/*
 * Spec 5.2, 5.13, 01-15 Part A without a database server: the empty statement, the driver check
 * and the container rule. The limiters run in tests/Integration.
 */

/** What QueryRunner::run() threw for $sql. */
function queryRunnerThrown(string $sql): ?Throwable
{
    try {
        app(QueryRunner::class)->run($sql);
    } catch (Throwable $e) {
        return $e;
    }

    return null;
}

it('QF1 an empty statement is refused before the database is asked', function (string $sql) {
    // The read-only connection is SQLite: reaching the session frame would throw UnsupportedDriver.
    currentTest()->bootWith();

    $thrown = queryRunnerThrown($sql);

    expect($thrown)->toBeInstanceOf(QueryFailed::class)
        ->and($thrown instanceof QueryFailed ? [$thrown->sqlState, $thrown->driverCode, $thrown->getMessage()] : null)
        ->toBe(['42601', null, QueryRunner::EMPTY]);
})->with([
    'nothing' => [''],
    'a semicolon' => [';'],
    'blanks and semicolons' => [" ;\n\t; "],
]);

it('QF2 any other statement goes to the session frame, which refuses SQLite', function () {
    currentTest()->bootWith();

    $thrown = queryRunnerThrown('SELECT 1');

    expect($thrown)->toBeInstanceOf(UnsupportedDriver::class)
        ->and($thrown instanceof UnsupportedDriver ? $thrown->driver : null)->toBe('sqlite');
});

it('QF3 QueryRunner is not a singleton: each make() gets the request\'s scoped ledger (rule 14)', function () {
    currentTest()->bootWith();

    expect(app(QueryRunner::class))->not->toBe(app(QueryRunner::class));
});
