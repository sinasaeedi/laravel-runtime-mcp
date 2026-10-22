<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Sql\Drivers;

use Illuminate\Database\Connection;
use PDOException;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\QueryRejected;

/**
 * Spec 5.2, 01-14 Part A: the driver part of the session frame. ReadOnlySession runs the steps in
 * order; each frame only knows its database's statements and error codes. Frames hold no state.
 */
interface SessionFrame
{
    /** Case A, at transaction level 0: on return the connection is in a read-only transaction with the timeout set. */
    public function open(Connection $connection, int $timeoutMs): void;

    /** Case B: true or false when the database can tell whether the open transaction is read-only, null when it cannot. */
    public function outerTransactionIsReadOnly(Connection $connection): ?bool;

    /** Case B, inside the savepoint: sets the timeout. */
    public function setTimeout(Connection $connection, int $timeoutMs): void;

    /** After the rollback to level 0: clears the session state (PostgreSQL) or ends the session (MySQL, MariaDB). */
    public function clearSession(Connection $connection): void;

    /** Maps a database error to one of the four QueryRejected classes (Part D). */
    public function translate(PDOException $e, int $timeoutMs): QueryRejected;
}
