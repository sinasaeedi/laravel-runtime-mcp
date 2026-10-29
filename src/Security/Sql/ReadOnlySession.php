<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Sql;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use PDO;
use PDOException;
use SinaSaeedi\RuntimeMcp\Security\Environment;
use SinaSaeedi\RuntimeMcp\Security\Sql\Drivers\Frames;
use SinaSaeedi\RuntimeMcp\Security\Sql\Drivers\SessionFrame;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\NotInReadOnlyTransaction;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\QueryFailed;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\QueryRejected;
use SinaSaeedi\RuntimeMcp\Security\UnsupportedDriver;
use Throwable;

/**
 * Spec 5.2, 5.13, 01-14 Part A: the session frame around agent SQL (01-15) and the package's own
 * queries (01-23, 01-24). $work runs on the read-only connection with native prepares, inside a
 * read-only transaction that always rolls back, under the statement timeout; then the session is
 * reset. No SQL is parsed (rule 2).
 *
 * Not bound in the container: each make() gets the current request's scoped SessionLedger. A
 * singleton would keep the first request's ledger under Octane (rule 14).
 */
final class ReadOnlySession
{
    public const SESSION_LOST = 'The connection was lost while the read-only session was set up. The query was not run.';

    public const CONNECTION_FAILED = 'The read-only connection could not be opened. The query was not run.';

    public const PERSISTENT = 'The read-only connection is persistent (PDO::ATTR_PERSISTENT), and a disconnect does not end a persistent session. The query was not run.';

    public const NO_READ_ONLY_CONNECTION = 'RUNTIME_MCP_DB_CONNECTION is not set or names the default connection, and a strict environment runs no package query on the default connection. The query was not run.';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Repository $config,
        private readonly SessionLedger $ledger,
        private readonly Frames $frames,
        private readonly TimeoutSetting $timeout,
        private readonly Environment $environment,
    ) {}

    /**
     * Inside $work a database error is still the raw QueryException (01-15 reads SQLSTATE 42601 there);
     * run() throws it as a QueryRejected, after the reset. Any other Throwable passes unchanged.
     *
     * @template T
     *
     * @param  Closure(Connection): T  $work
     * @return T
     *
     * @throws QueryRejected
     * @throws NotInReadOnlyTransaction
     * @throws UnsupportedDriver
     */
    public function run(Closure $work): mixed
    {
        $connection = $this->connection();
        $frame = $this->frame($connection);
        $timeoutMs = $this->timeout->milliseconds();

        try {
            return $connection->transactionLevel() === 0
                ? $this->runInOwnTransaction($connection, $frame, $timeoutMs, $work)
                : $this->runInSavepoint($connection, $frame, $timeoutMs, $work);
        } catch (PDOException $e) {
            // A QueryException, or a PDOException from beginTransaction() or a savepoint.
            throw $frame->translate($e, $timeoutMs);
        }
    }

    /**
     * Case A: the connection is not in a transaction (the normal case in every environment).
     *
     * @template T
     *
     * @param  Closure(Connection): T  $work
     * @return T
     */
    private function runInOwnTransaction(Connection $connection, SessionFrame $frame, int $timeoutMs, Closure $work): mixed
    {
        // frame() connected: the last call's disconnect() left the PDO null, and getPdo() does not reconnect.
        $pdo = $connection->getPdo();
        $this->refusePersistent($pdo);
        $emulated = $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);
        $lost = false;

        try {
            $frame->open($connection, $timeoutMs);

            if ($connection->getPdo() !== $pdo) {
                // At level 0 Laravel retries a lost-connection statement on a NEW session, and
                // beginTransaction() reconnects and sends a new BEGIN: that session may lack SET
                // TRANSACTION READ ONLY or the timeout. Agent SQL never runs on it.
                $lost = true;

                throw new QueryFailed('HY000', null, self::SESSION_LOST);
            }

            // After BEGIN: inside a transaction Laravel never reconnects, so this PDO runs $work.
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

            return $work($connection);
        } catch (PDOException $e) {
            // The same retry, when the new session cannot be opened: Laravel left a new (lazy) PDO, and
            // the connect error names the host, the port and the user (spec 4.3).
            if ($connection->getRawPdo() !== $pdo) {
                $lost = true;

                throw new QueryFailed('HY000', null, self::SESSION_LOST, $e);
            }

            throw $e;
        } finally {
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, $emulated);

            if ($lost) {
                $this->ledger->discard($connection);
            } else {
                $this->ledger->resetConnection($connection);
            }
        }
    }

    /**
     * Case B: 01-16 already holds a read-only transaction on this connection (it is the default
     * connection in a relaxed environment, or listed in read_only_transactions.connections).
     *
     * @template T
     *
     * @param  Closure(Connection): T  $work
     * @return T
     */
    private function runInSavepoint(Connection $connection, SessionFrame $frame, int $timeoutMs, Closure $work): mixed
    {
        $name = (string) $connection->getName();

        if (! ($frame->outerTransactionIsReadOnly($connection) ?? $this->ledger->isReadOnly($name))) {
            throw new NotInReadOnlyTransaction($name);
        }

        $pdo = $connection->getPdo();
        $this->refusePersistent($pdo);
        $emulated = $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);
        $level = $connection->transactionLevel();
        $connection->beginTransaction(); // a SAVEPOINT

        try {
            $frame->setTimeout($connection, $timeoutMs);
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

            return $work($connection);
        } finally {
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, $emulated);
            // DISCARD ALL cannot run inside 01-16's transaction; 01-16 resets after its own rollback.
            $this->ledger->deferReset($name);

            try {
                $connection->rollBack($level);
            } catch (Throwable) {
                $this->ledger->discard($connection);
            }
        }
    }

    /**
     * Picks the frame and, in Case A, opens the connection. A connect error names the host, the port and
     * the user, so the agent gets CONNECTION_FAILED (spec 4.3); the error itself stays the previous one.
     */
    private function frame(Connection $connection): SessionFrame
    {
        try {
            // Frames::for() connects to a mysql-driver server to ask whether it is MariaDB.
            $frame = $this->frames->for($connection);

            // Not in Case B: disconnect() keeps the transaction level, so a reconnect there would run the
            // savepoint on a new session outside any transaction.
            if ($connection->transactionLevel() === 0) {
                $connection->reconnectIfMissingConnection();
                $connection->getPdo(); // Opens Laravel's lazy connection.
            }
        } catch (PDOException $e) {
            $error = DatabaseError::of($e);

            // PostgreSQL's errorInfo[1] is always 7 (PostgresFrame::translate()).
            $driverCode = $connection->getDriverName() === 'pgsql' ? null : $error->driverCode;

            throw new QueryFailed($error->sqlState, $driverCode, self::CONNECTION_FAILED, $e);
        }

        return $frame;
    }

    /**
     * A disconnect() does not end a persistent session: MySQL and MariaDB reset by it, and a discard()
     * after a failed reset relies on it on every driver. Its locks and variables would reach the next call.
     */
    private function refusePersistent(PDO $pdo): void
    {
        if ($pdo->getAttribute(PDO::ATTR_PERSISTENT) === true) {
            throw new QueryFailed('HY000', null, self::PERSISTENT);
        }
    }

    private function connection(): Connection
    {
        $name = $this->config->get('runtime-mcp.database.connection');

        // 01-10's boot rule forbids this in a strict environment, but only while a plugin that requires the
        // read-only connection is active. The frame does not depend on its caller's plugin (principle 1.4.3).
        if ($this->environment->isStrict() && (! is_string($name) || trim($name) === '' || $name === $this->config->get('database.default'))) {
            throw new QueryFailed('HY000', null, self::NO_READ_ONLY_CONNECTION);
        }

        // null and '' both give the default connection (DatabaseManager::connection() uses ?:).
        return $this->db->connection(is_string($name) ? $name : null);
    }
}
