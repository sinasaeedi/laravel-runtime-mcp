<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Grants;

use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use PDO;

/**
 * Spec 5.1 on MySQL and MariaDB. The grants are read with SHOW GRANTS on the read-only connection,
 * as the user itself, together with every role it was granted, active or not (the default role can
 * change).
 *
 * Check 1 is an allow-list (Sina, 2026-10-20, Q2): only USAGE, SELECT and SHOW VIEW pass. MySQL and
 * MariaDB run DDL inside START TRANSACTION READ ONLY (it commits first), so the read-only transaction
 * of 01-14 does not stop ALTER, CREATE or INDEX there.
 *
 * A column is readable when a SELECT (or ALL PRIVILEGES) covers it: on *.*, on its database (a LIKE
 * pattern), on its table or on the column. information_schema is not used for this: it shows a column
 * for any privilege on it, also INSERT, and only for the active roles.
 *
 * Not read: MySQL's mandatory_roles server setting (a role every user gets without a GRANT line).
 * Keep it empty on a server with a read-only user; the docs say so.
 *
 * Reading the roles changes this connection's session role (SET ROLE): the doctor check purges the
 * connection afterwards. No grant is ever changed.
 */
final class MySqlPrivileges implements RolePrivileges
{
    public const ALLOWED = ['USAGE', 'SELECT', 'SHOW VIEW'];

    /** Server-wide rights: check 2. Every other privilege outside ALLOWED is check 1. */
    public const SUPERUSER = ['SUPER', 'FILE', 'PROXY', 'CREATE USER'];

    /** @var list<array{privilege: string, column: string|null, grant: MySqlGrant, via: string|null}>|null */
    private ?array $atoms = null;

    /** @var array{string, string}|null */
    private ?array $account = null;

    /**
     * @param  Connection  $connection  the read-only connection
     * @param  Connection  $app  the default connection: the read-only user cannot list a column it has no privilege on
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly Connection $app,
    ) {}

    public function writes(): array
    {
        return $this->findings(fn (array $atom): bool => ! in_array($atom['privilege'], self::ALLOWED, true) && ! self::isSuperuser($atom));
    }

    public function superuser(): array
    {
        return $this->findings(self::isSuperuser(...));
    }

    public function readable(ColumnMask $mask, DeniedTables $denied, string $prefix): array
    {
        $database = $this->connection->getDatabaseName();

        // What exists, from the app's connection: base tables and views (Sina, 2026-10-20, Q3).
        $tables = [];
        $views = [];

        foreach ($this->app->select(
            'select c.table_name as name, c.column_name as col, t.table_type as type from information_schema.columns c '
            .'join information_schema.tables t on t.table_schema = c.table_schema and t.table_name = c.table_name '
            .'where c.table_schema = ? order by c.table_name, c.ordinal_position',
            [$database],
        ) as $row) {
            $row = (array) $row;
            $name = self::text($row['name'] ?? null);
            $tables[$name][] = self::text($row['col'] ?? null);

            if (($row['type'] ?? null) !== 'BASE TABLE') {
                $views[$name] = true;
            }
        }

        $select = array_values(array_filter(
            $this->atoms(),
            fn (array $atom): bool => in_array($atom['privilege'], ['SELECT', 'ALL PRIVILEGES'], true),
        ));
        $findings = [];

        foreach ($tables as $table => $columns) {
            $isDenied = $denied->contains($database, $table, $prefix);
            $candidates = $isDenied ? $columns : array_values(array_filter($columns, $mask->matches(...)));
            $groups = [];

            foreach ($candidates as $column) {
                $covering = array_values(array_filter($select, fn (array $atom): bool => self::covers($atom, $database, $table, $column)));

                foreach ($covering as $atom) {
                    $level = $atom['grant']->targetKind === MySqlGrant::TABLE && $atom['column'] !== null ? 'column' : $atom['grant']->targetKind;
                    $groups[($atom['via'] ?? '')."\0".$level."\0".$atom['grant']->target][] = $column;
                }
            }

            foreach ($groups as $key => $readable) {
                $readable = array_values(array_unique($readable));
                $fix = $this->readableFix($key, $database, $table, $readable, $columns, $isDenied || isset($views[$table]), $mask);
                $suffix = self::through(explode("\0", $key)[0]);

                if ($isDenied) {
                    $findings[] = new PrivilegeFinding("{$table} (denied table){$suffix}", $fix);

                    continue;
                }

                foreach ($readable as $column) {
                    $findings[] = new PrivilegeFinding("{$table}.{$column}{$suffix}", $fix);
                }
            }
        }

        return $findings;
    }

    /**
     * @param  callable(array{privilege: string, column: string|null, grant: MySqlGrant, via: string|null}): bool  $filter
     * @return list<PrivilegeFinding>
     */
    private function findings(callable $filter): array
    {
        $groups = [];

        foreach ($this->atoms() as $atom) {
            if ($filter($atom)) {
                $text = $atom['column'] === null ? $atom['privilege'] : $atom['privilege'].' ('.RoleSqlGenerator::quoteMySql($atom['column']).')';
                $groups[($atom['via'] ?? '')."\0".$atom['grant']->target][] = $text;
            }
        }

        $findings = [];

        foreach ($groups as $key => $privileges) {
            [$via, $target] = explode("\0", $key, 2);
            $privileges = implode(', ', array_values(array_unique($privileges)));
            $fix = match ($via) {
                '' => "REVOKE {$privileges} ON {$target} FROM {$this->grantee()};",
                'PUBLIC' => "REVOKE {$privileges} ON {$target} FROM PUBLIC;",
                default => "REVOKE {$via} FROM {$this->grantee()};",
            };
            $findings[] = new PrivilegeFinding("{$privileges} on {$target}".self::through($via), $fix);
        }

        return $findings;
    }

    /**
     * @param  string  $key  "via\0level\0target"
     * @param  list<string>  $readable  the masked (or, for a denied table, all) columns this grant makes readable
     * @param  list<string>  $columns  every column of the table
     * @param  bool  $noGrant  a denied table or a view: 01-11 grants it nothing, so nothing is granted back
     */
    private function readableFix(string $key, string $database, string $table, array $readable, array $columns, bool $noGrant, ColumnMask $mask): string
    {
        [$via, $level, $target] = explode("\0", $key, 3);

        if ($via !== '' && $via !== 'PUBLIC') {
            return "REVOKE {$via} FROM {$this->grantee()};";
        }

        $from = $via === 'PUBLIC' ? 'PUBLIC' : $this->grantee();
        $qualified = RoleSqlGenerator::quoteMySql($database).'.'.RoleSqlGenerator::quoteMySql($table);

        if ($level === 'column') {
            return 'REVOKE SELECT ('.implode(', ', array_map(RoleSqlGenerator::quoteMySql(...), $readable)).") ON {$qualified} FROM {$from};";
        }

        if ($level === MySqlGrant::TABLE) {
            $fix = "REVOKE SELECT ON {$qualified} FROM {$from};";
            $keep = array_values(array_filter($columns, fn (string $column): bool => ! $mask->matches($column)));

            // A table-level grant covers every column, also the ones a migration adds later (drift).
            if (! $noGrant && $via === '' && $keep !== []) {
                $fix .= ' GRANT SELECT ('.implode(', ', array_map(RoleSqlGenerator::quoteMySql(...), $keep)).") ON {$qualified} TO {$from};";
            }

            return $fix;
        }

        return "REVOKE SELECT ON {$target} FROM {$from}; Then grant SELECT per table, as the role SQL of `runtime-mcp:install` does.";
    }

    /** @param  array{privilege: string, column: string|null, grant: MySqlGrant, via: string|null}  $atom */
    private static function isSuperuser(array $atom): bool
    {
        if (in_array($atom['privilege'], self::SUPERUSER, true)) {
            return true;
        }

        return $atom['grant']->targetKind === MySqlGrant::GLOBAL
            && ($atom['privilege'] === 'ALL PRIVILEGES' || str_ends_with($atom['privilege'], 'ADMIN'));
    }

    /** @param  array{privilege: string, column: string|null, grant: MySqlGrant, via: string|null}  $atom */
    private static function covers(array $atom, string $database, string $table, string $column): bool
    {
        $grant = $atom['grant'];

        if ($atom['column'] !== null && strcasecmp($atom['column'], $column) !== 0) {
            return false;
        }

        return match ($grant->targetKind) {
            MySqlGrant::GLOBAL => true,
            MySqlGrant::DATABASE => self::databaseMatches((string) $grant->database, $database),
            MySqlGrant::TABLE => strcasecmp((string) $grant->database, $database) === 0 && strcasecmp((string) $grant->table, $table) === 0,
            default => false,
        };
    }

    /** A database-level grant name is a LIKE pattern: % and _ are wildcards, \ escapes. The match ignores case: it can only report more. */
    private static function databaseMatches(string $pattern, string $database): bool
    {
        $regex = '';
        $length = strlen($pattern);

        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];

            if ($char === '\\' && $i + 1 < $length) {
                $regex .= preg_quote($pattern[++$i], '/');
            } elseif ($char === '%') {
                $regex .= '.*';
            } elseif ($char === '_') {
                $regex .= '.';
            } else {
                $regex .= preg_quote($char, '/');
            }
        }

        return preg_match('/^'.$regex.'$/iDs', $database) === 1;
    }

    private static function through(string $via): string
    {
        return match ($via) {
            '' => '',
            'PUBLIC' => ' (through PUBLIC)',
            default => " (through {$via})",
        };
    }

    /**
     * Every privilege the user holds, one per privilege and column, with where it comes from: null
     * (its own grant), "PUBLIC" (MariaDB), or the role as SHOW GRANTS writes it.
     *
     * @return list<array{privilege: string, column: string|null, grant: MySqlGrant, via: string|null}>
     */
    private function atoms(): array
    {
        if ($this->atoms !== null) {
            return $this->atoms;
        }

        [$user, $host] = $this->account();
        $atoms = [];

        if ($this->isMaria()) {
            // MariaDB: plain SHOW GRANTS names the grantee of every line; the read-only user may not
            // run SHOW GRANTS FOR a role, but after SET ROLE it sees that role and its nested roles.
            $lines = $this->showGrants('SHOW GRANTS');

            foreach ($lines as $grant) {
                if ($grant->isFor($user, $host) || $grant->public) {
                    array_push($atoms, ...self::split($grant, $grant->public ? 'PUBLIC' : null));
                }
            }

            foreach (self::roles($lines, $user, $host) as $role) {
                $this->exec("SET ROLE {$role}");

                foreach ($this->showGrants('SHOW GRANTS') as $grant) {
                    if (! $grant->isFor($user, $host) && ! $grant->public) {
                        array_push($atoms, ...self::split($grant, $role));
                    }
                }
            }
        } else {
            // MySQL: SHOW GRANTS merges the active roles, so turn them off for the user's own grants,
            // then add what each role brings (USING works for a granted role that is not active).
            $this->exec('SET ROLE NONE');
            $lines = $this->showGrants('SHOW GRANTS');
            $own = [];

            foreach ($lines as $grant) {
                if ($grant->isFor($user, $host)) {
                    foreach (self::split($grant, null) as $atom) {
                        $own[self::key($atom)] = true;
                        $atoms[] = $atom;
                    }
                }
            }

            foreach (self::roles($lines, $user, $host) as $role) {
                foreach ($this->showGrants("SHOW GRANTS FOR CURRENT_USER() USING {$role}") as $grant) {
                    foreach (self::split($grant, $role) as $atom) {
                        if ($grant->isFor($user, $host) && ! isset($own[self::key($atom)])) {
                            $atoms[] = $atom;
                        }
                    }
                }
            }
        }

        return $this->atoms = $atoms;
    }

    /**
     * @param  list<MySqlGrant>  $lines
     * @return list<string> the roles granted to the user itself, as written
     */
    private static function roles(array $lines, string $user, string $host): array
    {
        $roles = [];

        foreach ($lines as $grant) {
            if ($grant->isRoleGrant() && $grant->isFor($user, $host)) {
                array_push($roles, ...$grant->roles);
            }
        }

        return array_values(array_unique($roles));
    }

    /** @return list<array{privilege: string, column: string|null, grant: MySqlGrant, via: string|null}> */
    private static function split(MySqlGrant $grant, ?string $via): array
    {
        $atoms = [];

        foreach ($grant->privileges as $privilege) {
            foreach ($privilege['columns'] ?? [null] as $column) {
                $atoms[] = ['privilege' => $privilege['name'], 'column' => $column, 'grant' => $grant, 'via' => $via];
            }
        }

        if ($grant->grantOption && ! $grant->isRoleGrant()) {
            $atoms[] = ['privilege' => 'GRANT OPTION', 'column' => null, 'grant' => $grant, 'via' => $via];
        }

        return $atoms;
    }

    /** @param  array{privilege: string, column: string|null, grant: MySqlGrant, via: string|null}  $atom */
    private static function key(array $atom): string
    {
        return strtolower($atom['privilege']."\0".$atom['column']."\0".$atom['grant']->target);
    }

    /** @return list<MySqlGrant> */
    private function showGrants(string $sql): array
    {
        $statement = $this->connection->getPdo()->query($sql);
        $grants = [];

        foreach ($statement === false ? [] : $statement->fetchAll(PDO::FETCH_COLUMN, 0) as $line) {
            $grant = is_string($line) ? MySqlGrantParser::parse($line) : null;

            if ($grant !== null) {
                $grants[] = $grant;
            }
        }

        return $grants;
    }

    private function exec(string $sql): void
    {
        $this->connection->getPdo()->exec($sql);
    }

    private function isMaria(): bool
    {
        return $this->connection instanceof MySqlConnection && $this->connection->isMaria();
    }

    /** @return array{string, string} CURRENT_USER() is "user@host", unquoted: split at the last @ */
    private function account(): array
    {
        if ($this->account !== null) {
            return $this->account;
        }

        $row = $this->connection->selectOne('select current_user() as account');
        $account = self::text(is_object($row) ? ((array) $row)['account'] ?? null : null);
        $at = strrpos($account, '@');

        return $this->account = $at === false ? [$account, ''] : [substr($account, 0, $at), substr($account, $at + 1)];
    }

    /** The user, quoted for a fix line: `name`@`host`. */
    private function grantee(): string
    {
        [$user, $host] = $this->account();

        return RoleSqlGenerator::quoteMySql($user).'@'.RoleSqlGenerator::quoteMySql($host);
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
