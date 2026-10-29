<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Grants;

use Illuminate\Database\Connection;

/**
 * Spec 5.1 on PostgreSQL. Every query runs on the read-only connection and asks about current_user,
 * the role itself, so the answer is what the role really holds (a sample write proves little).
 *
 * has_*_privilege() counts a role's privileges only when the membership inherits. 01-11's role is
 * NOINHERIT, so a plain GRANT pg_write_all_data TO it is invisible to has_table_privilege(current_user)
 * although SET ROLE turns it on. Every check therefore asks for the role, for every role it is a
 * member of, and for PUBLIC (Sina, 2026-10-20, Q1). A superuser is a member of every role, so for a
 * superuser only the role itself is asked: check 2 already says why everything else fails.
 *
 * All schemas but the system ones are checked, not only the search_path: a schema-qualified name
 * reaches any schema.
 *
 * @phpstan-type Grant array{grantee: string, self: bool, owner: bool, tableLevel: bool, direct: list<string>}
 */
final class PostgresPrivileges implements RolePrivileges
{
    public const WRITE = 'INSERT, UPDATE, DELETE, TRUNCATE';

    /**
     * PostgreSQL 17's MAINTAIN (VACUUM, ANALYZE, REINDEX, CLUSTER, LOCK TABLE): a read-only transaction
     * does not stop REINDEX or LOCK TABLE … IN ACCESS EXCLUSIVE MODE, which blocks the app on that
     * table (probed on 17.11, 2026-10-29). Older servers have no such privilege.
     */
    public const MAINTAIN = 'MAINTAIN';

    /**
     * The roles granted to the role itself that make it a member of r (r too, when it is granted
     * directly), as JSON: "REVOKE r" does nothing for a role r that is only reached through another.
     */
    private const DIRECT = <<<'SQL'
        (select json_agg(distinct d.rolname::text order by d.rolname::text)
         from pg_auth_members m
         join pg_roles d on d.oid = m.roleid
         where m.member = (select u.oid from pg_roles u where u.rolname = current_user)
           and pg_has_role(d.oid, r.oid, 'MEMBER'))::text
        SQL;

    /** The role, the roles it is a member of, and PUBLIC ("public" is PUBLIC for has_*_privilege()). */
    private const GRANTEES = '
        with grantees as (
            select r.rolname::text as grantee, r.rolname = current_user as self, '.self::DIRECT.' as direct
            from pg_roles r
            where pg_has_role(current_user, r.oid, \'MEMBER\')
              and (r.rolname = current_user or not (select s.rolsuper from pg_roles s where s.rolname = current_user))
            union all
            select \'public\', false, null
        )
        ';

    private const USER_SCHEMAS = <<<'SQL'
        n.nspname not in ('pg_catalog', 'information_schema') and n.nspname not like 'pg\_%'
        SQL;

    public function __construct(private readonly Connection $connection) {}

    public function writes(): array
    {
        $write = $this->write();
        $rows = $this->connection->select(self::GRANTEES.'
            select n.nspname as schema, c.relname as name, g.grantee, g.self, g.direct,
                   pg_get_userbyid(c.relowner) = current_user as owner
            from pg_class c
            join pg_namespace n on n.oid = c.relnamespace
            cross join grantees g
            where c.relkind in (\'r\', \'p\') and '.self::USER_SCHEMAS.'
              and has_table_privilege(g.grantee::name, c.oid, \''.$write.'\')
            order by n.nspname, c.relname, g.self desc, g.grantee');

        $role = $this->role();
        $byTable = [];

        foreach ($rows as $row) {
            $row = (array) $row;
            $byTable[self::text($row['schema'] ?? null)."\0".self::text($row['name'] ?? null)][] = self::grant($row);
        }

        $findings = [];

        foreach ($byTable as $key => $grants) {
            [$schema, $name] = explode("\0", $key, 2);
            $label = "{$schema}.{$name}";
            $table = self::qualified($schema, $name);

            foreach (self::through($grants) as $grant) {
                $findings[] = $grant['grantee'] === 'public'
                    ? new PrivilegeFinding("{$label} (through PUBLIC)", "REVOKE {$write} ON TABLE {$table} FROM PUBLIC;")
                    : new PrivilegeFinding("{$label} (through {$grant['grantee']})", self::revokeRole($grant['grantee'], $grant['direct'], $role));
            }

            if (self::through($grants) === [] && $grants[0]['self']) {
                $findings[] = $grants[0]['owner']
                    ? self::owned($label, $table)
                    : new PrivilegeFinding($label, "REVOKE {$write} ON TABLE {$table} FROM ".RoleSqlGenerator::quotePostgres($role).';');
            }
        }

        return $findings;
    }

    public function superuser(): array
    {
        $rows = $this->connection->select('
            select r.rolname as role, r.rolname = current_user as self, r.rolsuper as super, r.rolbypassrls as bypass,
                   '.self::DIRECT.' as direct
            from pg_roles r
            where pg_has_role(current_user, r.oid, \'MEMBER\') and (r.rolsuper or r.rolbypassrls)
              and (r.rolname = current_user or not (select s.rolsuper from pg_roles s where s.rolname = current_user))
            order by r.rolname = current_user desc, r.rolname');

        $role = $this->role();
        $findings = [];

        foreach ($rows as $row) {
            $row = (array) $row;
            $rights = array_keys(array_filter(['SUPERUSER' => ($row['super'] ?? null) === true, 'BYPASSRLS' => ($row['bypass'] ?? null) === true]));
            $name = self::text($row['role'] ?? null);

            $findings[] = ($row['self'] ?? null) === true
                ? new PrivilegeFinding(
                    "{$name}: ".implode(', ', $rights),
                    'ALTER ROLE '.RoleSqlGenerator::quotePostgres($name).' '.implode(' ', array_map(fn (string $right): string => 'NO'.$right, $rights)).';',
                )
                : new PrivilegeFinding("member of {$name} (".implode(', ', $rights).')', self::revokeRole($name, self::direct($row['direct'] ?? null), $role));
        }

        return $findings;
    }

    public function readable(ColumnMask $mask, DeniedTables $denied, string $prefix): array
    {
        // Every relation and column of the user schemas: the catalog is readable for every role.
        $relations = [];

        foreach ($this->connection->select('
            select c.oid, n.nspname as schema, c.relname as name, c.relkind as kind, c.relispartition as partition, a.attname as column
            from pg_class c
            join pg_namespace n on n.oid = c.relnamespace
            join pg_attribute a on a.attrelid = c.oid and a.attnum > 0 and not a.attisdropped
            where c.relkind in (\'r\', \'p\', \'v\', \'m\', \'f\') and '.self::USER_SCHEMAS.'
            order by n.nspname, c.relname, a.attnum') as $row) {
            $row = (array) $row;
            $oid = self::text($row['oid'] ?? null);
            $relations[$oid] ??= [
                'schema' => self::text($row['schema'] ?? null),
                'name' => self::text($row['name'] ?? null),
                // Views, materialized views and foreign tables too (Sina, 2026-10-20, Q3): a view
                // runs with its owner's rights, whatever the column grants of its tables say.
                'base' => in_array($row['kind'] ?? null, ['r', 'p'], true) && ($row['partition'] ?? null) !== true,
                'columns' => [],
            ];
            $relations[$oid]['columns'][] = self::text($row['column'] ?? null);
        }

        // Who can read which column, and whether through a table-level grant.
        $readable = [];

        foreach ($this->connection->select(self::GRANTEES.'
            select c.oid, a.attname as column, g.grantee, g.self, g.direct,
                   pg_get_userbyid(c.relowner) = current_user as owner,
                   has_table_privilege(g.grantee::name, c.oid, \'SELECT\') as table_level
            from pg_class c
            join pg_namespace n on n.oid = c.relnamespace
            join pg_attribute a on a.attrelid = c.oid and a.attnum > 0 and not a.attisdropped
            cross join grantees g
            where c.relkind in (\'r\', \'p\', \'v\', \'m\', \'f\') and '.self::USER_SCHEMAS.'
              and has_column_privilege(g.grantee::name, c.oid, a.attnum, \'SELECT\')') as $row) {
            $row = (array) $row;
            $readable[self::text($row['oid'] ?? null)][self::text($row['column'] ?? null)][] = self::grant($row);
        }

        $role = $this->role();
        $findings = [];

        foreach ($relations as $oid => $relation) {
            $label = "{$relation['schema']}.{$relation['name']}";
            $table = self::qualified($relation['schema'], $relation['name']);
            $columns = $readable[$oid] ?? [];

            if ($denied->contains($relation['schema'], $relation['name'], $prefix)) {
                $grants = $columns === [] ? [] : array_merge(...array_values($columns));
                array_push($findings, ...self::deniedFindings($label, $table, $grants, $role));

                continue;
            }

            $masked = array_values(array_filter(array_keys($columns), fn (string $column): bool => $mask->matches($column)));

            if ($masked === []) {
                continue;
            }

            array_push($findings, ...self::maskedFindings($label, $table, $relation, $masked, $columns, $mask, $role));
        }

        return $findings;
    }

    /**
     * @param  list<Grant>  $grants
     * @return list<PrivilegeFinding>
     */
    private static function deniedFindings(string $label, string $table, array $grants, string $role): array
    {
        if ($grants === []) {
            return [];
        }

        $findings = [];

        foreach (self::through($grants) as $grant) {
            $findings[] = $grant['grantee'] === 'public'
                ? new PrivilegeFinding("{$label} (denied table, through PUBLIC)", "REVOKE ALL ON TABLE {$table} FROM PUBLIC;")
                : new PrivilegeFinding("{$label} (denied table, through {$grant['grantee']})", self::revokeRole($grant['grantee'], $grant['direct'], $role));
        }

        if ($findings === []) {
            $findings[] = self::owns($grants)
                ? self::owned("{$label} (denied table)", $table)
                : new PrivilegeFinding("{$label} (denied table)", "REVOKE ALL ON TABLE {$table} FROM ".RoleSqlGenerator::quotePostgres($role).';');
        }

        return $findings;
    }

    /**
     * @param  array{schema: string, name: string, base: bool, columns: list<string>}  $relation
     * @param  list<string>  $masked  the readable masked columns, in table order
     * @param  array<string, list<Grant>>  $columns  readable column => who can read it
     * @return list<PrivilegeFinding>
     */
    private static function maskedFindings(string $label, string $table, array $relation, array $masked, array $columns, ColumnMask $mask, string $role): array
    {
        $findings = [];
        $self = [];

        foreach ($masked as $column) {
            $item = "{$label}.{$column}";
            $through = self::through($columns[$column]);

            foreach ($through as $grant) {
                $findings[] = $grant['grantee'] === 'public'
                    ? new PrivilegeFinding("{$item} (through PUBLIC)", self::revokeSelect($table, $columns[$column], [$column], 'PUBLIC'))
                    : new PrivilegeFinding("{$item} (through {$grant['grantee']})", self::revokeRole($grant['grantee'], $grant['direct'], $role));
            }

            if ($through === []) {
                $self[] = $column;
            }
        }

        if ($self === []) {
            return $findings;
        }

        $rows = array_merge(...array_map(fn (string $column): array => $columns[$column], $self));
        $grantee = RoleSqlGenerator::quotePostgres($role);

        if (self::owns($rows)) {
            $fix = self::owned($label, $table)->fix;
        } elseif (self::tableLevel($rows) && $relation['base']) {
            // A table-level grant covers every column, also the ones a migration adds later (drift).
            $keep = array_values(array_filter($relation['columns'], fn (string $column): bool => ! $mask->matches($column)));
            $fix = "REVOKE SELECT ON TABLE {$table} FROM {$grantee};";

            if ($keep !== []) {
                $fix .= ' GRANT SELECT ('.implode(', ', array_map(RoleSqlGenerator::quotePostgres(...), $keep)).") ON TABLE {$table} TO {$grantee};";
            }
        } else {
            $fix = self::revokeSelect($table, $rows, $self, $grantee);
        }

        foreach ($self as $column) {
            $findings[] = new PrivilegeFinding("{$label}.{$column}", $fix);
        }

        return $findings;
    }

    /**
     * REVOKE SELECT ON TABLE also takes the column grants away, so it is the fix for a table-level
     * grant (and for every view); a column grant gets a column REVOKE.
     *
     * @param  list<Grant>  $rows
     * @param  list<string>  $columns
     */
    private static function revokeSelect(string $table, array $rows, array $columns, string $grantee): string
    {
        if (self::tableLevel($rows)) {
            return "REVOKE SELECT ON TABLE {$table} FROM {$grantee};";
        }

        return 'REVOKE SELECT ('.implode(', ', array_map(RoleSqlGenerator::quotePostgres(...), $columns)).") ON TABLE {$table} FROM {$grantee};";
    }

    /**
     * The grantees other than the role itself (member roles, PUBLIC), in row order, one row each. When
     * one of them holds the privilege, revoking the role's own grant would not be enough, so it is named
     * first; after that fix, the next doctor run shows what is left. When PUBLIC holds it, only PUBLIC
     * is named: every role holds what PUBLIC holds, so a member role's answer says nothing more.
     *
     * @param  list<Grant>  $grants
     * @return list<Grant>
     */
    private static function through(array $grants): array
    {
        $through = [];

        foreach ($grants as $grant) {
            if ($grant['grantee'] === 'public') {
                return [$grant];
            }

            if (! $grant['self']) {
                $through[$grant['grantee']] ??= $grant;
            }
        }

        return array_values($through);
    }

    /** @param  list<Grant>  $rows */
    private static function owns(array $rows): bool
    {
        foreach ($rows as $row) {
            if ($row['self'] && $row['owner']) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<Grant>  $rows */
    private static function tableLevel(array $rows): bool
    {
        foreach ($rows as $row) {
            if ($row['tableLevel']) {
                return true;
            }
        }

        return false;
    }

    /** An owner holds every privilege, and no REVOKE takes them away. */
    private static function owned(string $label, string $table): PrivilegeFinding
    {
        return new PrivilegeFinding(
            "{$label} (the role owns it)",
            "ALTER TABLE {$table} OWNER TO <the app's own role>; -- an owner keeps every privilege",
        );
    }

    /**
     * The REVOKE of each role granted to the role itself that leads to $member: for a role that is
     * only reached through another, "REVOKE <member>" does nothing.
     *
     * @param  list<string>  $direct
     */
    private static function revokeRole(string $member, array $direct, string $role): string
    {
        return implode(' ', array_map(
            fn (string $granted): string => 'REVOKE '.RoleSqlGenerator::quotePostgres($granted).' FROM '.RoleSqlGenerator::quotePostgres($role).';',
            $direct === [] ? [$member] : $direct,
        ));
    }

    private static function qualified(string $schema, string $name): string
    {
        return RoleSqlGenerator::quotePostgres($schema).'.'.RoleSqlGenerator::quotePostgres($name);
    }

    /** The privileges check 1 asks for: WRITE, and MAINTAIN on PostgreSQL 17 and later. */
    private function write(): string
    {
        $row = $this->connection->selectOne("select current_setting('server_version_num')::int as version");
        $version = is_object($row) ? ((array) $row)['version'] ?? null : null;

        return is_numeric($version) && (int) $version >= 170000 ? self::WRITE.', '.self::MAINTAIN : self::WRITE;
    }

    private function role(): string
    {
        $row = $this->connection->selectOne('select current_user as role');

        return self::text(is_object($row) ? ((array) $row)['role'] ?? null : null);
    }

    /**
     * @param  array<array-key, mixed>  $row
     * @return Grant
     */
    private static function grant(array $row): array
    {
        return [
            'grantee' => self::text($row['grantee'] ?? null),
            'self' => ($row['self'] ?? null) === true,
            'owner' => ($row['owner'] ?? null) === true,
            'tableLevel' => ($row['table_level'] ?? null) === true,
            'direct' => self::direct($row['direct'] ?? null),
        ];
    }

    /** @return list<string> the JSON list of DIRECT */
    private static function direct(mixed $value): array
    {
        $names = is_string($value) ? json_decode($value, true) : null;

        return is_array($names) ? array_values(array_filter($names, is_string(...))) : [];
    }

    private static function text(mixed $value): string
    {
        return is_string($value) || is_int($value) ? (string) $value : '';
    }
}
