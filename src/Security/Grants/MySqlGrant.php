<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Grants;

/**
 * One parsed line of MySQL or MariaDB SHOW GRANTS (MySqlGrantParser): a privilege grant
 * ("GRANT SELECT (`id`), INSERT ON `app`.`orders` TO `u`@`%`") or a role grant
 * ("GRANT `r`@`%` TO `u`@`%`"). It never holds the IDENTIFIED BY PASSWORD part.
 */
final readonly class MySqlGrant
{
    public const GLOBAL = 'global';     // ON *.*

    public const DATABASE = 'database'; // ON `db`.* ($database may hold the wildcards % and _)

    public const TABLE = 'table';       // ON `db`.`table` (a table or a view)

    public const ROUTINE = 'routine';   // ON PROCEDURE … / FUNCTION … / PACKAGE …

    public const PROXY = 'proxy';       // ON ''@''

    /**
     * @param  list<array{name: string, columns: list<string>|null}>  $privileges  upper case, one space between words, ALL as ALL PRIVILEGES; empty for a role grant.
     *                                                                             An item the parser cannot read is kept as written (one space between words)
     * @param  string  $target  the ON part as SHOW GRANTS wrote it ('' for a role grant), reused in fix lines
     * @param  list<string>  $roles  a role grant: each role as SHOW GRANTS wrote it ("`r`@`%`", "`r`"), reused in SQL
     * @param  string  $granteeName  unquoted; 'PUBLIC' for MariaDB's PUBLIC
     * @param  string|null  $granteeHost  unquoted; null for a MariaDB role and for PUBLIC
     */
    public function __construct(
        public array $privileges,
        public string $targetKind,
        public ?string $database,
        public ?string $table,
        public string $target,
        public array $roles,
        public string $granteeName,
        public ?string $granteeHost,
        public bool $public,
        public bool $grantOption,
    ) {}

    public function isRoleGrant(): bool
    {
        return $this->roles !== [];
    }

    /** True when the grant is to this account (the host compare ignores case, as MySQL does). */
    public function isFor(string $user, string $host): bool
    {
        return ! $this->public
            && $this->granteeName === $user
            && $this->granteeHost !== null
            && strcasecmp($this->granteeHost, $host) === 0;
    }
}
