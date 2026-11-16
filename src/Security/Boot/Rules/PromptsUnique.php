<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Boot\Rules;

use SinaSaeedi\RuntimeMcp\Mcp\McpToolAdapter;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootIssue;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootRule;

/**
 * Spec 5.11: two prompts (an active plugin's or a custom one) share a name. prompts/get would answer the
 * first and never the second, so the endpoint stays off. A later release that adds a clashing name trips
 * it too (fail closed).
 */
final class PromptsUnique implements BootRule
{
    public const ID = 'prompts.unique';

    /** %1$s is the name, %2$s and %3$s the classes. */
    public const DUPLICATE = 'Two prompts are named `%1$s`: `%2$s` and `%3$s`.';

    public function __construct(private readonly PromptInstances $prompts) {}

    public function id(): string
    {
        return self::ID;
    }

    public function check(): array
    {
        $seen = [];
        $issues = [];

        foreach ($this->prompts->all() as $prompt) {
            $name = $prompt->name();

            if (isset($seen[$name])) {
                // A name that is not valid is prompts.name's issue; it is never printed.
                $shown = preg_match(McpToolAdapter::NAME_PATTERN, $name) === 1 ? $name : '(not a valid name)';
                $issues[] = BootIssue::error(self::ID, sprintf(self::DUPLICATE, $shown, $seen[$name], $prompt::class));

                continue;
            }

            $seen[$name] = $prompt::class;
        }

        return $issues;
    }
}
