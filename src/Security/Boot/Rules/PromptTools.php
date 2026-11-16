<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Boot\Rules;

use SinaSaeedi\RuntimeMcp\Security\Boot\BootIssue;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootRule;

/**
 * Spec 5.14: a custom prompt that needs a tool which is not registered, or names no tool, never shows
 * (default deny). A warning, not an error: the endpoint is safe, the prompt is only useless. The built-in
 * prompts are skipped: a plugin that is off takes its prompts away by design, and health_check (core)
 * needs queue_overview, so a queue plugin that is off would warn on every boot.
 */
final class PromptTools implements BootRule
{
    public const ID = 'prompts.tools';

    /** %1$s is the prompt name, %2$s the tool name: both source code. */
    public const NOT_REGISTERED = 'Prompt `%1$s` needs `%2$s`, which is not registered, so the prompt never shows.';

    public const NO_TOOL = 'Prompt `%s` names no tool, so it never shows.';

    public function __construct(private readonly PromptInstances $prompts) {}

    public function id(): string
    {
        return self::ID;
    }

    public function check(): array
    {
        $registered = array_flip($this->prompts->toolNames());
        $issues = [];

        foreach ($this->prompts->custom() as $prompt) {
            $tools = $prompt->tools();

            if ($tools === []) {
                $issues[] = BootIssue::warning(self::ID, sprintf(self::NO_TOOL, $prompt->name()));
            }

            foreach ($tools as $tool) {
                if (! isset($registered[$tool])) {
                    $issues[] = BootIssue::warning(self::ID, sprintf(self::NOT_REGISTERED, $prompt->name(), $tool));
                }
            }
        }

        return $issues;
    }
}
