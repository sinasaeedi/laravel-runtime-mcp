<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\ReadOnly;

use RuntimeException;

/** Spec 5.9, 01-16 Part A (Sina, Q1): app code inside a read tool tried to commit the read-only transaction. */
final class ReadOnlyCommitRefused extends RuntimeException
{
    public const MESSAGE = 'A read tool cannot commit: it runs in a read-only transaction.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
