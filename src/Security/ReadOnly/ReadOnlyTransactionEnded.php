<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\ReadOnly;

use RuntimeException;

/**
 * Spec 5.9, 01-16 Part B: app code ended the read-only transaction during the call (a reconnect, a
 * purge, or a commit through the PDO). ReadOnlyStage fails the call with this as its error class.
 */
final class ReadOnlyTransactionEnded extends RuntimeException
{
    public function __construct(public readonly string $connection)
    {
        parent::__construct('App code ended the read-only transaction on '.ReadOnlyTransactionUnavailable::shown($connection).'.');
    }
}
