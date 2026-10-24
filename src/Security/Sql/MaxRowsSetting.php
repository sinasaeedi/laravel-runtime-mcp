<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Sql;

use Illuminate\Contracts\Config\Repository;

/**
 * Spec 4.5, 5.8, 01-15: database.max_rows, read on every call (rule 14). A bad value gives 500, never 0,
 * like TimeoutSetting. 01-20 reads the same value for the db_query schema and description.
 */
final class MaxRowsSetting
{
    public const DEFAULT = 500;

    public function __construct(private readonly Repository $config) {}

    public function rows(): int
    {
        $value = $this->config->get('runtime-mcp.database.max_rows');

        // (int) of a longer digit string gives PHP_INT_MAX: refused, because the runner adds 1.
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        return is_int($value) && $value >= 1 && $value < PHP_INT_MAX ? $value : self::DEFAULT;
    }
}
