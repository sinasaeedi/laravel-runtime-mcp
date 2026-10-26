<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\ReadOnly;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Events\TransactionRolledBack;
use SinaSaeedi\RuntimeMcp\Security\Sql\SessionLedger;

/**
 * Spec 5.9 (Sina, 2026-10-26, the triage of review-01-16 M1): an unbalanced DB::rollBack() by app code
 * does not end the read-only transaction.
 *
 * No event fires before a rollback, so it cannot be refused as RefuseReadOnlyCommit refuses a commit.
 * After the rollback the connection is at level 0, where Laravel retries PostgreSQL's 25006 on a new,
 * read-write session. So a new transaction begins at once. The session default is still read-only, so
 * the new transaction is read-only too, and a later write fails at level 1, with no retry.
 *
 * ReadOnlyTransaction::close() ends the marks before its own rollBack(0), so this never reopens it.
 * Registered for every request (RuntimeMcpServiceProvider::boot()); built per event by the dispatcher.
 */
final class ReopenReadOnlyTransaction
{
    public function __construct(private readonly Container $container) {}

    public function handle(TransactionRolledBack $event): void
    {
        // Never resolved: no tool ran, so no connection is marked (see RefuseReadOnlyCommit).
        if (! $this->container->resolved(SessionLedger::class)) {
            return;
        }

        // A rollback to a savepoint leaves the read-only transaction open.
        if ($event->connection->transactionLevel() > 0) {
            return;
        }

        if ($this->container->make(SessionLedger::class)->isReadOnly($event->connectionName)) {
            $event->connection->beginTransaction();
        }
    }
}
