<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins\Database;

use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\QueryPermissionDenied;

/**
 * Spec 5.1, 01-20: the table that a permission error names, read from the database's own message, never
 * from the agent's SQL (rule 2). It lives here, not in src/Security/Sql/, because it searches strings
 * (NoSqlParsingTest). The pattern follows the message shape, not the driver name: a mysql-driver
 * connection can reach MariaDB, which quotes 1142 differently.
 *
 * Measured forms (01-11 and 01-20 audits; PostgreSQL 17, MySQL 8.4, MariaDB 11.4 and 10.11):
 *
 *   PostgreSQL 42501  ERROR:  permission denied for table users | view v | materialized view m
 *   MySQL 1142        SELECT command denied to user 'u'@'h' for table 'sessions'
 *   MariaDB 1142      SELECT command denied to user 'u'@'h' for table `db`.`sessions`
 *   both 1143         SELECT command denied to user 'u'@'h' for column 'password' in table 'users'
 *
 * 1044 and 1370, and PostgreSQL's schema, function and sequence errors, name no table: null.
 */
final class PermissionMessage
{
    private const POSTGRES = '/^(?:ERROR:\s+)?permission denied for (?:table|view|materialized view) (.+)$/D';

    // 1142, MySQL: 'name'. Greedy, so a name with a quote still ends at the last one.
    private const QUOTED = "/ for table '(.+)'$/D";

    // 1142, MariaDB: `db`.`name` or `name`; a backtick in a name is doubled.
    private const BACKTICKED = '/ for table `((?:[^`]|``)+)`(?:\.`((?:[^`]|``)+)`)?$/D';

    // 1143 on both: 'name', or `name` as a fallback.
    private const COLUMN = "/ for column (?:'.+'|`.+`) in table (?:'(.+)'|`((?:[^`]|``)+)`)$/D";

    /** "name", or "schema.name" when the error gives the schema; null when the error names no table. */
    public static function table(QueryPermissionDenied $e): ?string
    {
        // Only the first line: PostgreSQL may add DETAIL or HINT lines.
        $message = explode("\n", $e->getMessage(), 2)[0];

        return match ($e->driverCode) {
            null => self::match(self::POSTGRES, $message),
            1142 => self::backticked($message) ?? self::match(self::QUOTED, $message),
            1143 => self::column($message),
            default => null,
        };
    }

    private static function match(string $pattern, string $message): ?string
    {
        return preg_match($pattern, $message, $m) === 1 && $m[1] !== '' ? $m[1] : null;
    }

    private static function backticked(string $message): ?string
    {
        if (preg_match(self::BACKTICKED, $message, $m) !== 1) {
            return null;
        }

        return implode('.', array_map(fn (string $name): string => str_replace('``', '`', $name), array_slice($m, 1)));
    }

    private static function column(string $message): ?string
    {
        if (preg_match(self::COLUMN, $message, $m) !== 1) {
            return null;
        }

        // The quoted form fills group 1, the backticked form group 2.
        return $m[1] !== '' ? $m[1] : str_replace('``', '`', $m[2]);
    }
}
