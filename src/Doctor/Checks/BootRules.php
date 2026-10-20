<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Doctor\Checks;

use SinaSaeedi\RuntimeMcp\Doctor\Check;
use SinaSaeedi\RuntimeMcp\Doctor\CheckResult;
use SinaSaeedi\RuntimeMcp\Security\Boot\Severity;
use SinaSaeedi\RuntimeMcp\Security\BootValidator;

/**
 * Spec 5.11, 7.3: every boot validation rule (01-10), core and plugin, also when the endpoint is off.
 * One result per issue. The 01-10 messages already say what to do, so there is no separate fix.
 */
final class BootRules implements Check
{
    public function __construct(private readonly BootValidator $validator) {}

    public function title(): string
    {
        return 'Boot rules';
    }

    public function run(): array
    {
        $issues = $this->validator->validate()->issues;

        if ($issues === []) {
            return [CheckResult::pass('Every boot rule passes.')];
        }

        $results = [];

        foreach ($issues as $issue) {
            $message = "`{$issue->ruleId}`: {$issue->message}";

            $results[] = $issue->severity === Severity::Error
                ? CheckResult::fail($message)
                : CheckResult::warn($message);
        }

        return $results;
    }
}
