<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions;

/**
 * Spec 5.2, 01-15 Part C: MySQL or MariaDB refused the wrapped form (1064, 1060, 3954), so nothing ran.
 * Not a PDOException, so ReadOnlySession::run() lets it pass untranslated.
 */
final class QueryNotLimitable extends QueryRejected {}
