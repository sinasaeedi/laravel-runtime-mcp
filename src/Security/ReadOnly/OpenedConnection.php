<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\ReadOnly;

use Illuminate\Database\Connection;
use PDO;

/**
 * 01-16 Part A: one connection that ReadOnlyTransaction set to read-only. $pdo is the session it set:
 * a different PDO on close means app code reconnected. $restore puts the session default back.
 */
final readonly class OpenedConnection
{
    public function __construct(
        public string $name,
        public Connection $connection,
        public PDO $pdo,
        public string $restore,
    ) {}
}
