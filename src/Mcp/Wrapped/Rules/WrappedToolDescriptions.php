<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Mcp\Wrapped\Rules;

use Illuminate\Support\Str;
use SinaSaeedi\RuntimeMcp\Mcp\Wrapped\WrappedTools;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootIssue;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootRule;

/**
 * 01-38: a wrapped tool whose description is laravel/mcp's class-name fallback, the make:mcp-tool stub's text, or
 * blank. A warning: the tool works, but the agent cannot tell when to use it. Logged at boot (throttled) and shown by
 * doctor's BootRules check. It builds the valid entries, so a throwing official tool is its "could not run" error.
 */
final class WrappedToolDescriptions implements BootRule
{
    public const ID = 'wrapped_tools.description';

    /** %s is the config name, which passed McpToolAdapter::NAME_PATTERN. */
    public const NO_DESCRIPTION = 'Wrapped tool `%s` has no description; add #[Description] so the agent knows when to use it.';

    /** The description that laravel/mcp's mcp-tool.stub writes. */
    public const STUB_TEXT = 'A description of what this tool does.';

    public function __construct(private readonly WrappedTools $tools) {}

    public function id(): string
    {
        return self::ID;
    }

    public function check(): array
    {
        $issues = [];

        foreach ($this->tools->build() as $tool) {
            $description = trim($tool->description());
            $fallback = Str::headline(class_basename($tool->wrappedClass())); // Primitive::description()'s fallback

            if ($description === '' || $description === $fallback || $description === self::STUB_TEXT) {
                $issues[] = BootIssue::warning(self::ID, sprintf(self::NO_DESCRIPTION, $tool->name()));
            }
        }

        return $issues;
    }
}
