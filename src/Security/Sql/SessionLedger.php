<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Sql;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use SinaSaeedi\RuntimeMcp\Security\Sql\Drivers\Frames;
use Throwable;

/**
 * Spec 5.2, 5.13, 01-14 Part B: which connections 01-16 holds in a read-only transaction, and which
 * ones still need the session reset that a nested ReadOnlySession call could not run (DISCARD ALL
 * cannot run inside 01-16's transaction). Scoped (rule 14): a new ledger for each request.
 *
 * 01-16 calls markReadOnly() when it opens its transaction, and after its rollback reset() for each of
 * pendingResets() and forget() for each connection it opened.
 */
final class SessionLedger
{
    /** @var list<string> */
    private array $readOnly = [];

    /** @var list<string> */
    private array $pending = [];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Frames $frames,
    ) {}

    public function markReadOnly(string $connection): void
    {
        if (! $this->isReadOnly($connection)) {
            $this->readOnly[] = $connection;
        }
    }

    public function isReadOnly(string $connection): bool
    {
        return in_array($connection, $this->readOnly, true);
    }

    /** ReadOnlySession Case B: this connection's session must be reset after the outer rollback. */
    public function deferReset(string $connection): void
    {
        if (! $this->needsReset($connection)) {
            $this->pending[] = $connection;
        }
    }

    public function needsReset(string $connection): bool
    {
        return in_array($connection, $this->pending, true);
    }

    /** @return list<string> */
    public function pendingResets(): array
    {
        return $this->pending;
    }

    /** The per-driver reset of Part A, for a connection by name. Never throws. */
    public function reset(string $connection): void
    {
        // By getName(), the name Case B deferred: a config `name` key differs from the config key, and
        // DatabaseManager::connection() would throw for it. A new connection would also run app listeners.
        foreach ($this->db->getConnections() as $made) {
            if ($made->getName() === $connection) {
                $this->resetConnection($made);

                return;
            }
        }

        // Not made, or purged (discard()): no session is left to reset.
        $this->pending = array_values(array_diff($this->pending, [$connection]));
    }

    /**
     * Rolls back to level 0, then DISCARD ALL (PostgreSQL) or disconnect (MySQL, MariaDB). Never throws:
     * a connection whose state could not be reset is discarded, and the exception of the call (if any)
     * stays the one the caller sees.
     */
    public function resetConnection(Connection $connection): void
    {
        $this->pending = array_values(array_diff($this->pending, [(string) $connection->getName()]));

        try {
            $connection->rollBack(0);
            $this->frames->for($connection)->clearSession($connection);
        } catch (Throwable) {
            $this->discard($connection);
        }
    }

    /** Throws the connection away: it is never used again (principle 1.4.3); the next use makes a new one. */
    public function discard(Connection $connection): void
    {
        try {
            $connection->disconnect();
        } catch (Throwable) {
            // Nothing to keep: the purge below drops the connection anyway.
        }

        $this->db->purge($connection->getName());
    }

    /**
     * 01-16, after its rollback: the connection is no longer in its read-only transaction, so a later
     * MySQL Case B in the same request must not trust the old mark. A pending reset stays pending.
     */
    public function forget(string $connection): void
    {
        $this->readOnly = array_values(array_diff($this->readOnly, [$connection]));
    }
}
