<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions;

/** Spec 5.2, 01-14 Part D. The read-only role has no privilege: PostgreSQL 42501, MySQL and MariaDB 1142, 1143 and 1044 (Sina, 2026-10-21). */
final class QueryPermissionDenied extends QueryRejected {}
