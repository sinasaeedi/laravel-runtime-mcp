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
            // RuntimeServer::tools() lists only classes that exist; this check is defence in depth, as McpPromptAdapters.
            $tool = class_exists($class) ? $container->make($class) : null;

            if (! $tool instanceof RuntimeTool) {
                throw new LogicException("[{$class}] is not a RuntimeTool.");
            }

            $adapters[] = new McpToolAdapter($tool, $container);
        }

        return $adapters;
    }

    /**
     * The registered tools by name, hidden ones too: PromptGate and PromptContext look a prompt's tool up
     * here (01-36). A name that is not a key is not registered.
     *
     * @param  list<McpToolAdapter>  $adapters
     * @return array<string, RuntimeTool>
     */
    public static function byName(array $adapters): array
    {
        $tools = [];

        foreach ($adapters as $adapter) {
            $tools[$adapter->name()] = $adapter->runtimeTool();
        }

        return $tools;
    }
}
