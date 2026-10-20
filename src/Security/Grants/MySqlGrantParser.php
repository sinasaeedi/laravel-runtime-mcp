<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Grants;

/**
 * Spec 5.1, check 1: parses one line of MySQL or MariaDB SHOW GRANTS. Pure: no database.
 *
 * Names are quoted (`…`, '…' or "…") and can hold commas, dots, spaces and the words ON and TO, so
 * the parser first masks every quoted or bracketed character and only then looks for the keywords,
 * the commas and the dots. A line that is not a GRANT (SET DEFAULT ROLE, a MySQL partial REVOKE) is
 * null. The IDENTIFIED BY PASSWORD '…' part of a MariaDB line holds the password hash: it is never
 * kept, and no exception or message ever holds the line.
 */
final class MySqlGrantParser
{
    private const ROUTINE = '/^(?:PROCEDURE|FUNCTION|PACKAGE\s+BODY|PACKAGE)\s+/i';

    public static function parse(string $line): ?MySqlGrant
    {
        $line = trim($line);

        if (preg_match('/^GRANT\s+/i', $line, $match) !== 1) {
            return null;
        }

        $body = substr($line, strlen($match[0]));
        $masked = self::mask($body);

        if (preg_match('/\sTO\s/i', $masked, $to, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $head = substr($body, 0, $to[0][1]);
        $tail = substr($body, $to[0][1] + strlen($to[0][0]));
        $maskedTail = self::mask($tail);

        // The grantee is the first word of the tail (quoted parts are masked, so it has no space).
        if (preg_match('/^\S+/', $maskedTail, $first) !== 1) {
            return null;
        }

        $grantee = substr($tail, 0, strlen($first[0]));
        $rest = substr($maskedTail, strlen($first[0]));
        [$name, $host] = self::account($grantee);
        $public = $host === null && strcasecmp($grantee, 'PUBLIC') === 0;
        $grantOption = preg_match('/\bWITH\s+GRANT\s+OPTION\b/i', $rest) === 1;

        $on = preg_match('/\sON\s/i', self::mask($head), $onMatch, PREG_OFFSET_CAPTURE) === 1 ? $onMatch[0] : null;

        if ($on === null) {
            $roles = array_values(array_filter(array_map(trim(...), self::split($head, ',')), fn (string $role): bool => $role !== ''));

            return new MySqlGrant([], '', null, null, '', $roles, $public ? 'PUBLIC' : $name, $host, $public, $grantOption);
        }

        $privileges = [];

        foreach (self::split(substr($head, 0, $on[1]), ',') as $item) {
            $privilege = self::privilege($item);

            if ($privilege !== null) {
                $privileges[] = $privilege;
            }
        }

        $target = trim(substr($head, $on[1] + strlen($on[0])));
        [$kind, $database, $table] = self::target($target);

        return new MySqlGrant($privileges, $kind, $database, $table, $target, [], $public ? 'PUBLIC' : $name, $host, $public, $grantOption);
    }

    /** Removes `…`, '…' or "…" around a name and undoes the doubled quote inside. A bare word stays as it is. */
    public static function unquote(string $name): string
    {
        $name = trim($name);
        $quote = $name[0] ?? '';

        if (strlen($name) >= 2 && in_array($quote, ['`', "'", '"'], true) && str_ends_with($name, $quote)) {
            return str_replace($quote.$quote, $quote, substr($name, 1, -1));
        }

        return $name;
    }

    /**
     * Every character inside quotes or brackets (and the quotes and brackets themselves) becomes "_",
     * so the masked text has the same length and only top-level keywords, commas and dots.
     */
    private static function mask(string $text): string
    {
        $out = '';
        $quote = null;
        $depth = 0;
        $length = strlen($text);

        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];

            if ($quote !== null) {
                if ($char === '\\' && $quote !== '`' && $i + 1 < $length) {
                    $out .= '__';
                    $i++;

                    continue;
                }

                if ($char === $quote) {
                    $quote = null;
                }

                $out .= '_';

                continue;
            }

            if ($char === '`' || $char === "'" || $char === '"') {
                $quote = $char;
                $out .= '_';
            } elseif ($char === '(') {
                $depth++;
                $out .= '_';
            } elseif ($char === ')') {
                $depth = max(0, $depth - 1);
                $out .= '_';
            } else {
                $out .= $depth > 0 ? '_' : $char;
            }
        }

        return $out;
    }

    /**
     * Splits $text at each top-level $separator.
     *
     * @return list<string>
     */
    private static function split(string $text, string $separator): array
    {
        $masked = self::mask($text);
        $parts = [];
        $start = 0;

        while (($at = strpos($masked, $separator, $start)) !== false) {
            $parts[] = substr($text, $start, $at - $start);
            $start = $at + strlen($separator);
        }

        $parts[] = substr($text, $start);

        return $parts;
    }

    /** @return array{name: string, columns: list<string>|null}|null */
    private static function privilege(string $item): ?array
    {
        $item = trim($item);

        if (preg_match('/^([A-Za-z_][A-Za-z0-9_ ]*?)\s*(?:\((.*)\))?$/s', $item, $match) !== 1) {
            return null;
        }

        $name = strtoupper((string) preg_replace('/\s+/', ' ', $match[1]));
        $columns = null;

        if (isset($match[2])) {
            $columns = array_values(array_filter(
                array_map(self::unquote(...), self::split($match[2], ',')),
                fn (string $column): bool => $column !== '',
            ));
        }

        return ['name' => $name === 'ALL' ? 'ALL PRIVILEGES' : $name, 'columns' => $columns];
    }

    /** @return array{string, string|null, string|null} kind, database, table */
    private static function target(string $target): array
    {
        if (preg_match(self::ROUTINE, $target) === 1) {
            return [MySqlGrant::ROUTINE, null, null];
        }

        $target = (string) preg_replace('/^TABLE\s+/i', '', $target);

        if (str_contains(self::mask($target), '@')) {
            return [MySqlGrant::PROXY, null, null];
        }

        $parts = self::split($target, '.');

        // SHOW GRANTS always writes db.table; anything else is read as global, which can only report more.
        if (count($parts) !== 2) {
            return [MySqlGrant::GLOBAL, null, null];
        }

        [$database, $table] = array_map(trim(...), $parts);

        if ($database === '*') {
            return [MySqlGrant::GLOBAL, null, null];
        }

        if ($table === '*') {
            return [MySqlGrant::DATABASE, self::unquote($database), null];
        }

        return [MySqlGrant::TABLE, self::unquote($database), self::unquote($table)];
    }

    /** @return array{string, string|null} the unquoted name and host of "`u`@`h`"; a role or PUBLIC has no host */
    private static function account(string $account): array
    {
        $parts = self::split($account, '@');

        if (count($parts) < 2) {
            return [self::unquote($account), null];
        }

        $host = array_pop($parts);

        return [self::unquote(implode('@', $parts)), self::unquote($host)];
    }
}
