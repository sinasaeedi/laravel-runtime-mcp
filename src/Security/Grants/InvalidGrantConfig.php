<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Grants;

use InvalidArgumentException;

/**
 * runtime-mcp.database.masked_columns or denied_tables is not an array. The role SQL is not generated:
 * ignoring the list would grant every column or every table (principle 1.4.3, Sina 2026-10-19).
 */
final class InvalidGrantConfig extends InvalidArgumentException
{
    /** @param 'masked_columns'|'denied_tables' $key */
    public function __construct(public readonly string $key)
    {
        parent::__construct("runtime-mcp.database.{$key} must be an array of names. No role SQL was generated.");
    }
}
