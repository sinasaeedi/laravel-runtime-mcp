<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Boot\Rules;

use SinaSaeedi\RuntimeMcp\Mcp\Methods\CallTool;
use SinaSaeedi\RuntimeMcp\Mcp\Wrapped\WrappedTool;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootIssue;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootRule;

/**
 * Spec 5.11, 5.17, 10.1 (01-40): no tool may use a name of laravel/mcp's two catalog meta-tools. With the catalog on,
 * ServerContext::tools() throws "Duplicate server tool name" on every tools/list for such a tool, and tools.unique
 * (01-29) compares our tools with each other only. Checked whatever the catalog setting: tool names are public API, so a
 * name must not break on the day the operator turns the catalog on. The names are package constants, so the message may
 * print them; it names the class (a wrapped tool's official class, as tools.unique does), never a config value.
 */
final class ToolsReserved implements BootRule
{
    public const ID = 'tools.reserved';

    /** The names of Laravel\Mcp\Server\Tools\SearchTools and ExecuteTools. */
    public const RESERVED = CallTool::META_TOOLS;

    /** %1$s is the class, %2$s the name. */
    public const RESERVED_NAME = 'Tool `%1$s` is named `%2$s`, which laravel/mcp\'s tool catalog reserves. Give it another name.';

    public function __construct(private readonly ToolInstances $tools) {}

    public function id(): string
    {
        return self::ID;
    }

    public function check(): array
    {
        $issues = [];

        foreach ($this->tools->all() as $tool) {
            $name = $tool->name();

            if (in_array($name, self::RESERVED, true)) {
                $class = $tool instanceof WrappedTool ? $tool->wrappedClass() : $tool::class;
                $issues[] = BootIssue::error(self::ID, sprintf(self::RESERVED_NAME, $class, $name));
            }
        }

        return $issues;
    }
}
