<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Grants;

/**
 * Spec 5.1: the three privilege checks, asked of the database as the read-only role itself.
 * PostgresPrivileges and MySqlPrivileges; the doctor check (01-13) picks one by driver.
 * Each method reads only; an empty list means the check passes.
 */
interface RolePrivileges
{
    /** @return list<PrivilegeFinding> check 1: a privilege beyond reading */
    public function writes(): array;

    /** @return list<PrivilegeFinding> check 2: superuser rights */
    public function superuser(): array;

    /**
     * Check 3: a readable masked column (masked_columns) or a readable denied table (denied_tables).
     * $prefix is the table prefix of the app's default connection.
     *
     * @return list<PrivilegeFinding>
     */
    public function readable(ColumnMask $mask, DeniedTables $denied, string $prefix): array;
}
