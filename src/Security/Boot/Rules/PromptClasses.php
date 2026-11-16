<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Boot\Rules;

use Illuminate\Contracts\Config\Repository;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootIssue;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootRule;

/**
 * Spec 5.11, 5.14: every entry of runtime-mcp.prompts is a concrete class that extends RuntimePrompt.
 * The message names the position only: a wrong entry could be any text (BootIssue).
 */
final class PromptClasses implements BootRule
{
    public const ID = 'prompts.class';

    public const NOT_A_LIST = 'runtime-mcp.prompts is not a list of class names.';

    /** %d is the position, from 0. */
    public const NOT_A_PROMPT = 'Entry %d of runtime-mcp.prompts is not a class that extends RuntimePrompt.';

    public function __construct(private readonly Repository $config) {}

    public function id(): string
    {
        return self::ID;
    }

    public function check(): array
    {
        $entries = $this->config->get('runtime-mcp.prompts');

        if ($entries === null) {
            return [];
        }

        if (! is_array($entries) || ! array_is_list($entries)) {
            return [BootIssue::error(self::ID, self::NOT_A_LIST)];
        }

        $issues = [];

        foreach ($entries as $position => $class) {
            if (! PromptInstances::isPromptClass($class)) {
                $issues[] = BootIssue::error(self::ID, sprintf(self::NOT_A_PROMPT, $position));
            }
        }

        return $issues;
    }
}
