<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\ReadOnly;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Events\TransactionCommitting;
use SinaSaeedi\RuntimeMcp\Security\Sql\SessionLedger;

/**
 * Spec 5.9, 01-16 Part A (Sina, Q1): refuses a commit of the read-only transaction by app code.
 *
 * Laravel lists PostgreSQL's "SQLSTATE[25006]: Read only sql transaction" as a lost connection. At
 * transaction level 0 it reconnects and runs the write again on a new, read-write session. So once app
 * code has committed our transaction (an unbalanced DB::commit()), the read-only session default no
 * longer stops a write on PostgreSQL. Laravel fires "committing" only at level 1, before PDO::commit(),
 * so a throw here keeps the transaction open. A nested DB::transaction() (level 2) does not fire it.
 *
 * Registered for every request (RuntimeMcpServiceProvider::boot()); built per event by the dispatcher.
 */
final class RefuseReadOnlyCommit
{
    public function __construct(private readonly Container $container) {}

    public function handle(TransactionCommitting $event): void
    {
        // Never resolved: no tool ran, so no connection is marked. Under Octane resolved() stays true after
        // the worker's first tool call (forgetScopedInstances() keeps it), so make() may build an empty one.
        if (! $this->container->resolved(SessionLedger::class)) {
            return;
        }

        if ($this->container->make(SessionLedger::class)->isReadOnly($event->connectionName)) {
            throw new ReadOnlyCommitRefused;
        }
    }
}
