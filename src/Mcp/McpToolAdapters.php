<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Mcp;

use Illuminate\Contracts\Container\Container;
use LogicException;
use SinaSaeedi\RuntimeMcp\Server\RuntimeServer;
use SinaSaeedi\RuntimeMcp\Tools\Contracts\RuntimeTool;

/**
 * Builds one McpToolAdapter per registered tool, also the ones the Gate hides. McpServer registers
 * them, and Methods\CallTool searches them, so both see the same list (01-09).
 */
final class McpToolAdapters
{
    /**
     * Spec 4.2: every tool directly, each with an integer key, never under ToolSearch::class
     * (01-40 adds the operator's catalog switch). Each tool is wrapped in its own McpToolAdapter,
     * the only object laravel/mcp ever calls (spec 4.4).
     *
     * @return list<McpToolAdapter>
     */
    public static function fromDefinition(RuntimeServer $definition, Container $container): array
    {
        $adapters = [];

        foreach ($definition->tools() as $class) {
            $tool = $container->make($class);

            if (! $tool instanceof RuntimeTool) {
                throw new LogicException("[{$class}] is not a RuntimeTool.");
            }

            $adapters[] = new McpToolAdapter($tool, $container);
        }

        return $adapters;
    }
}
