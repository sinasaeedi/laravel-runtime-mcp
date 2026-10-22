<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Sql\Drivers;

/**
 * Spec 5.2 on MariaDB (supported by Sina's decision, 2026-10-02): the MySQL frame with another timeout.
 * MariaDB has no max_execution_time (1193 Unknown system variable); max_statement_time is in seconds
 * and stops every statement type. Checked on MariaDB 10.11 and 11.4.
 */
final class MariaDbFrame extends MySqlFrame
{
    protected function timeoutStatement(int $timeoutMs): string
    {
        return 'SET SESSION max_statement_time = '.number_format($timeoutMs / 1000, 3, '.', '');
    }

    /** ER_STATEMENT_TIMEOUT (SQLSTATE 70100). */
    protected function timeoutCode(): int
    {
        return 1969;
    }
}
