<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Doctor\Checks;

use SinaSaeedi\RuntimeMcp\Doctor\Check;
use SinaSaeedi\RuntimeMcp\Doctor\CheckResult;
use SinaSaeedi\RuntimeMcp\Security\Sql\TimeoutSetting;

/**
 * Spec 4.5, 5.2, 01-14 Part C: database.statement_timeout_ms. A bad value is replaced by 5000 at run
 * time, so it fails here: the operator thinks another timeout is in force. Never prints the value
 * (Sina, 2026-10-20: print a config value only when it has the shape of a name).
 */
final class StatementTimeout implements Check
{
    public function __construct(private readonly TimeoutSetting $timeout) {}

    public function title(): string
    {
        return 'Statement timeout';
    }

    public function run(): array
    {
        if (! $this->timeout->isValid()) {
            return [CheckResult::fail(
                'database.statement_timeout_ms is not a whole number from 1 to '.TimeoutSetting::MAX_MS.', so '.TimeoutSetting::DEFAULT_MS.' is used.',
                'Set it to the timeout in milliseconds, for example 5000.',
            )];
        }

        return [CheckResult::pass('Statements time out after '.$this->timeout->milliseconds().' ms.')];
    }
}
