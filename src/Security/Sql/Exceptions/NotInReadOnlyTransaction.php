<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions;

use RuntimeException;

/**
 * Spec 5.2, 01-14 Part A Case B: the connection is already in a transaction that is not known to be
 * read-only, so agent SQL does not run in it. Not a database answer: 01-20 treats it as a tool failure.
 */
final class NotInReadOnlyTransaction extends RuntimeException
{
    public function __construct(public readonly string $connection)
    {
        parent::__construct("Agent SQL runs only in a read-only transaction; connection {$connection} is in a transaction that is not known to be read-only.");
    }
}
