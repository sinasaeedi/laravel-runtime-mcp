<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions;

/** Spec 5.2, 01-14 Part D. The SQL tried to write: PostgreSQL 25006, MySQL and MariaDB 1792 (the read-only transaction). */
final class ReadOnlyViolation extends QueryRejected {}
