<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Mcp;

use Illuminate\Contracts\Container\Container;
use LogicException;
use SinaSaeedi\RuntimeMcp\Prompts\Contracts\RuntimePrompt;
use SinaSaeedi\RuntimeMcp\Server\RuntimeServer;
use SinaSaeedi\RuntimeMcp\Tools\Contracts\RuntimeTool;

/**
 * Builds one McpPromptAdapter per registered prompt, also the ones the Gate hides. McpServer registers
 * them, and Methods\GetPrompt searches them, so both see the same list (01-36, as McpToolAdapters).
 */
final class McpPromptAdapters
{
    /**
     * @param  array<string, RuntimeTool>  $tools  the registered tools by name (McpToolAdapters::byName())
     * @return list<McpPromptAdapter>
     */
    public static function fromDefinition(RuntimeServer $definition, Container $container, array $tools): array
    {
        $adapters = [];

        foreach ($definition->prompts() as $class) {
            $prompt = class_exists($class) ? $container->make($class) : null;

            if (! $prompt instanceof RuntimePrompt) {
                throw new LogicException("[{$class}] is not a RuntimePrompt.");
            }

            $adapters[] = new McpPromptAdapter($prompt, $tools, $container);
        }

        return $adapters;
    }
}
