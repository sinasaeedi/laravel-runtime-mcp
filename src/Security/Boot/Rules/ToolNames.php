<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Boot\Rules;

use SinaSaeedi\RuntimeMcp\Mcp\McpToolAdapter;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootIssue;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootRule;

/**
 * Spec 4.3, 10.1 (01-29): a custom tool's name is lower snake case, at most 64 characters (McpToolAdapter::NAME_PATTERN,
 * with /D, so a trailing newline is no match). McpToolAdapter refuses any other name on every request, so the endpoint
 * stays off instead. The built-in names are package code; the contract test proves them (01-18).
 */
final class ToolNames implements BootRule
{
    public const ID = 'tools.name';

    /** %s is the class: source code, never a config value. */
    public const BAD_NAME = 'Tool `%s` has a name that is not lower snake case of at most 64 characters.';

    public function __construct(private readonly ToolInstances $tools) {}

    public function id(): string
    {
        return self::ID;
    }

    public function check(): array
    {
        $issues = [];

        foreach ($this->tools->custom() as $tool) {
            if (preg_match(McpToolAdapter::NAME_PATTERN, $tool->name()) !== 1) {
                $issues[] = BootIssue::error(self::ID, sprintf(self::BAD_NAME, $tool::class));
            }
        }

        return $issues;
    }
}
