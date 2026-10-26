<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\ReadOnly;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Arr;
use RuntimeException;
use SinaSaeedi\RuntimeMcp\Security\Sql\SessionLedger;
use Throwable;

/**
 * Spec 5.9, 5.13, 01-16 Part A: the read-only transaction around a read tool, on the default
 * connection and on each connection in read_only_transactions.connections. It always rolls back.
 *
 * The session default is read-only too, and restored after: on MySQL and MariaDB it stops a write
 * after app code ended the transaction. On PostgreSQL it does not (Laravel retries a 25006 at level 0
 * on a new session), so RefuseReadOnlyCommit refuses the commit and close() detects a reconnect.
 *
 * Not bound in the container: it holds the connections of one call, and each make() gets the current
 * request's scoped SessionLedger (rule 14).
 */
final class ReadOnlyTransaction
{
    /** The drivers with a read-only transaction. SQLite is for the package's own tests (spec 3.1). */
    public const DRIVERS = ['pgsql', 'mysql', 'mariadb', 'sqlite'];

    private const BEGIN = 'BEGIN';

    /** @var list<OpenedConnection> */
    private array $opened = [];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Repository $config,
        private readonly SessionLedger $ledger,
        private readonly ExceptionHandler $exceptions,
    ) {}

    /**
     * Opens the transaction on every connection, or on none: a failure closes the ones already open.
     *
     * @throws ReadOnlyTransactionUnavailable
     */
    public function open(): void
    {
        foreach ($this->names() as $name) {
            try {
                $this->openOne($name);
            } catch (Throwable $e) {
                $this->close();

                if ($e instanceof ReadOnlyTransactionUnavailable) {
                    throw $e;
                }

                // The agent gets the class only (a connect error names the host); the log gets it all.
                $this->report($e);

                throw ReadOnlyTransactionUnavailable::failed($name, $e);
            }
        }
    }

    /**
     * Ends the read-only marks, rolls every connection back to level 0, restores its session default, and
     * runs the session resets that 01-14 left pending. Never throws: a connection it cannot restore is
     * discarded, so a session in an unknown state is never reused (Octane keeps the default connection).
     *
     * @return list<string> the connections on which app code ended the transaction during the call
     */
    public function close(): array
    {
        // First: a rollback below of a connection still marked would make ReopenReadOnlyTransaction begin again.
        foreach ($this->opened as $opened) {
            $this->ledger->forget(self::ledgerName($opened->connection, $opened->name));
        }

        $ended = [];

        foreach (array_reverse($this->opened) as $opened) {
            $connection = $opened->connection;

            // A new PDO: app code reconnected or purged. No PDO transaction: it committed (DB::commit() is
            // refused by RefuseReadOnlyCommit, so through getPdo()->commit()). The raw PDO: after reconnect()
            // it is a lazy closure, and getPdo() would connect, so it could throw.
            if ($connection->getRawPdo() !== $opened->pdo || ! $opened->pdo->inTransaction()) {
                $ended[] = $opened->name;
            }

            $this->undo($opened);
        }

        // After the rollback: DISCARD ALL cannot run inside a transaction (01-14 Case B). reset() never throws.
        foreach ($this->ledger->pendingResets() as $name) {
            $this->ledger->reset($name);
        }

        $this->opened = [];

        return $ended;
    }

    private function openOne(string $name): void
    {
        $connection = $this->db->connection($name);

        // The pipeline is the outermost caller: an open transaction is leaked state, or a test's RefreshDatabase.
        if ($connection->transactionLevel() > 0) {
            throw ReadOnlyTransactionUnavailable::inTransaction($name);
        }

        $driver = $connection->getDriverName();

        if (! in_array($driver, self::DRIVERS, true)) {
            throw ReadOnlyTransactionUnavailable::driver($name, $driver);
        }

        // After a disconnect() getPdo() is null, and it does not reconnect (01-14).
        $connection->reconnectIfMissingConnection();
        $pdo = $connection->getPdo();
        $opened = new OpenedConnection($name, $connection, $pdo, $this->restoreStatement($connection, $driver));

        try {
            foreach ($this->openStatements($driver) as $sql) {
                if ($sql === self::BEGIN) {
                    $connection->beginTransaction();
                } else {
                    $connection->statement($sql);
                }
            }

            // At level 0 Laravel retries a lost connection on a new session, and beginTransaction()
            // reconnects: that session may lack the read-only default (01-14 guards agent SQL alike).
            if ($connection->getPdo() !== $pdo) {
                throw ReadOnlyTransactionUnavailable::lost($name);
            }
        } catch (Throwable $e) {
            $this->undo($opened);

            throw $e;
        }

        // 01-14 Case B trusts this mark on MySQL and MariaDB; close() ends it with forget().
        $this->ledger->markReadOnly(self::ledgerName($connection, $name));
        $this->opened[] = $opened;
    }

    /** @return list<string> */
    private function openStatements(string $driver): array
    {
        return match ($driver) {
            // SET SESSION CHARACTERISTICS does not change the transaction in progress, so SET TRANSACTION too.
            'pgsql' => ['SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY', self::BEGIN, 'SET TRANSACTION READ ONLY'],
            'sqlite' => ['PRAGMA query_only = ON', self::BEGIN],
            // Not a raw START TRANSACTION READ ONLY: Laravel's level would stay 0 (01-14). The session
            // default makes the next transaction read-only, and every autocommit statement after it.
            default => ['SET SESSION TRANSACTION READ ONLY', self::BEGIN],
        };
    }

    /**
     * The statement that puts the session default back as it was. Read with the write PDO: at level 0 a
     * connection with read and write hosts reads from the read host, but every statement here goes to
     * the write session.
     */
    private function restoreStatement(Connection $connection, string $driver): string
    {
        if ($driver === 'pgsql') {
            $on = $connection->scalar('SHOW default_transaction_read_only', [], false) === 'on';

            return 'SET SESSION CHARACTERISTICS AS TRANSACTION '.($on ? 'READ ONLY' : 'READ WRITE');
        }

        if ($driver === 'sqlite') {
            $on = in_array($connection->scalar('PRAGMA query_only', [], false), [1, '1'], true);

            return 'PRAGMA query_only = '.($on ? 'ON' : 'OFF');
        }

        // MySQL 8.4 has transaction_read_only only, MariaDB 10.11 tx_read_only only, 11.4 both (same value).
        $rows = $connection->select("SHOW VARIABLES WHERE Variable_name IN ('transaction_read_only', 'tx_read_only')", [], false);
        $value = isset($rows[0]) && is_object($rows[0]) ? (get_object_vars($rows[0])['Value'] ?? null) : null;

        if (! is_string($value)) {
            throw new RuntimeException('The server has no transaction_read_only or tx_read_only variable.');
        }

        return 'SET SESSION TRANSACTION '.(strtoupper($value) === 'ON' ? 'READ ONLY' : 'READ WRITE');
    }

    /** Back to level 0 and the old session default; a new PDO, or a failure, discards the connection. */
    private function undo(OpenedConnection $opened): void
    {
        $connection = $opened->connection;

        if ($connection->getRawPdo() !== $opened->pdo) {
            $this->ledger->discard($connection);

            return;
        }

        try {
            $connection->rollBack(0);
            $connection->statement($opened->restore);
        } catch (Throwable $e) {
            $this->report($e);
            $this->ledger->discard($connection);
        }
    }

    /**
     * The name the ledger keys by. TransactionCommitting and 01-14's ReadOnlySession use getName(), and a
     * `name` key in the connection's config wins over the config key (ConnectionFactory::parseConfig()).
     */
    private static function ledgerName(Connection $connection, string $name): string
    {
        $own = $connection->getName();

        return is_string($own) && $own !== '' ? $own : $name;
    }

    /**
     * The default connection first, then each listed one, de-duplicated. Config only: a name is checked
     * as DatabaseManager finds it (Arr::get()), and no connection is opened to check it.
     *
     * @return list<string>
     */
    private function names(): array
    {
        $default = $this->config->get('database.default');

        if (! is_string($default) || ! $this->configured($default)) {
            throw ReadOnlyTransactionUnavailable::noDefault();
        }

        $listed = $this->config->get('runtime-mcp.read_only_transactions.connections') ?? [];

        if (! is_array($listed) || ! array_is_list($listed)) {
            throw ReadOnlyTransactionUnavailable::badConfig();
        }

        $names = [$default];

        foreach ($listed as $name) {
            if (! is_string($name) || $name === '') {
                throw ReadOnlyTransactionUnavailable::badConfig();
            }

            if (! $this->configured($name)) {
                throw ReadOnlyTransactionUnavailable::unknown($name);
            }

            $names[] = $name;
        }

        return array_values(array_unique($names));
    }

    private function configured(string $name): bool
    {
        $connections = $this->config->get('database.connections');

        return $name !== '' && is_array($connections) && Arr::get($connections, $name) !== null;
    }

    /** The handler may fail too; close() must never throw. */
    private function report(Throwable $e): void
    {
        try {
            $this->exceptions->report($e);
        } catch (Throwable) {
            // Nothing left to tell.
        }
    }
}
