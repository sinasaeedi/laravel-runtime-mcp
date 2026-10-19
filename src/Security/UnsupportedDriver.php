<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security;

use RuntimeException;

/** Spec 3.1: PostgreSQL, MySQL and MariaDB only. 01-14, 01-19 and 01-20 throw the same class. */
final class UnsupportedDriver extends RuntimeException
{
    public function __construct(public readonly string $driver)
    {
        parent::__construct("Only PostgreSQL, MySQL and MariaDB are supported; this connection uses {$driver}.");
    }
}
