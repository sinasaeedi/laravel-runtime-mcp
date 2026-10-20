<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Grants;

/**
 * One thing the read-only role may do but must not (spec 5.1), and the SQL that takes it away.
 * doctor prints the fix; it never runs it. Several findings can share one fix (one REVOKE for a
 * table): the doctor check prints each fix once.
 */
final readonly class PrivilegeFinding
{
    public function __construct(
        public string $item, // what is wrong, for example "public.orders (through pg_write_all_data)"
        public string $fix,  // one or more SQL statements, each ending in ;
    ) {}
}
