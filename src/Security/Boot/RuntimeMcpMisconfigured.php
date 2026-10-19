<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Boot;

use RuntimeException;

/**
 * Spec 4.6: a failed boot validation in a relaxed environment. Thrown from the service provider, so
 * the developer sees every error at once. Never thrown in a strict environment (rule 15).
 *
 * The message holds the same texts as the log: no secret.
 */
final class RuntimeMcpMisconfigured extends RuntimeException
{
    public function __construct(public readonly BootReport $report, string $environment)
    {
        $lines = array_map(
            fn (BootIssue $issue): string => "- [{$issue->ruleId}] {$issue->message}",
            $report->errors(),
        );

        parent::__construct(
            "The Runtime MCP security config is not valid in the `{$environment}` environment:\n"
            .implode("\n", $lines)
            ."\nFix these, or set RUNTIME_MCP_ENABLED=false. Strict environments keep the endpoint off instead of throwing."
        );
    }
}
