<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Boot\Rules;

use SinaSaeedi\RuntimeMcp\Mcp\McpToolAdapter;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootIssue;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootRule;

/**
 * Spec 5.14, 10.1: a prompt name is lower snake case, at most 64 characters, as a tool name
 * (McpToolAdapter::NAME_PATTERN). It is public API: the slash command is built from it.
 */
final class PromptNames implements BootRule
{
    public const ID = 'prompts.name';

    /** %s is the class: source code, never a config value. */
    public const BAD_NAME = 'Prompt `%s` has a name that is not lower snake case of at most 64 characters.';

    public function __construct(private readonly PromptInstances $prompts) {}

    public function id(): string
    {
        return self::ID;
    }

    public function check(): array
    {
        $issues = [];

        foreach ($this->prompts->all() as $prompt) {
            if (preg_match(McpToolAdapter::NAME_PATTERN, $prompt->name()) !== 1) {
                $issues[] = BootIssue::error(self::ID, sprintf(self::BAD_NAME, $prompt::class));
            }
        }

        return $issues;
    }
}
