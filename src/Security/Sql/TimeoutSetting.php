<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Sql;

use Illuminate\Contracts\Config\Repository;

/**
 * Spec 4.5, 5.2, 01-14 Part C: database.statement_timeout_ms, read on every call (rule 14). A bad value
 * gives 5000, never 0: 0 turns the timeout off on PostgreSQL, MySQL and MariaDB. The doctor check
 * StatementTimeout reports a bad value.
 */
final class TimeoutSetting
{
    public const DEFAULT_MS = 5000;

    // PostgreSQL's limit for statement_timeout (above it: "Value exceeds integer range"); MySQL and MariaDB accept it.
    public const MAX_MS = 2_147_483_647;

    public function __construct(private readonly Repository $config) {}

    public function milliseconds(): int
    {
        return $this->valid() ?? self::DEFAULT_MS;
    }

    public function isValid(): bool
    {
        return $this->valid() !== null;
    }

    /** An int from 1 to MAX_MS, or a string of digits only with that value (an env() value). Else null. */
    private function valid(): ?int
    {
        $value = $this->config->get('runtime-mcp.database.statement_timeout_ms');

        // (int) of a longer digit string gives PHP_INT_MAX, which is above MAX_MS: still refused.
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        return is_int($value) && $value >= 1 && $value <= self::MAX_MS ? $value : null;
    }
}
