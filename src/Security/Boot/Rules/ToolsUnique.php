<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Boot\Rules;

use SinaSaeedi\RuntimeMcp\Mcp\McpToolAdapter;
use SinaSaeedi\RuntimeMcp\Mcp\Wrapped\WrappedTool;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootIssue;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootRule;

/**
 * Spec 5.11 (01-29): two tools (an active plugin's or a custom one) share a name. tools/call would run the first and
 * never the second, and the catalog throws on it (01-40), so the endpoint stays off. A later release that adds a
 * clashing name trips it too (fail closed). ToolInstances::all() holds the wrapped tools too (01-38); the message names
 * the official laravel/mcp class of a wrapped tool, never WrappedTool.
 */
final class ToolsUnique implements BootRule
{
    public const ID = 'tools.unique';

    /** %1$s is the name, %2$s and %3$s the classes. */
    public const DUPLICATE = 'Two tools are named `%1$s`: `%2$s` and `%3$s`.';

    public function __construct(private readonly ToolInstances $tools) {}

    public function id(): string
    {
        return self::ID;
    }

    public function check(): array
    {
        $seen = [];
        $issues = [];

        foreach ($this->tools->all() as $tool) {
            $name = $tool->name();
            $class = $tool instanceof WrappedTool ? $tool->wrappedClass() : $tool::class;

            if (isset($seen[$name])) {
                // A name that is not valid is tools.name's issue; it is never printed.
                $shown = preg_match(McpToolAdapter::NAME_PATTERN, $name) === 1 ? $name : '(not a valid name)';
                $issues[] = BootIssue::error(self::ID, sprintf(self::DUPLICATE, $shown, $seen[$name], $class));

                continue;
            }

            $seen[$name] = $class;
        }

        return $issues;
    }
}
