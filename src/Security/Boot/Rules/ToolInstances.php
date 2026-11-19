<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Boot\Rules;

use Illuminate\Contracts\Container\Container;
use LogicException;
use SinaSaeedi\RuntimeMcp\Mcp\McpToolAdapter;
use SinaSaeedi\RuntimeMcp\Plugins\PluginRegistry;
use SinaSaeedi\RuntimeMcp\Tools\Contracts\RuntimeTool;
use SinaSaeedi\RuntimeMcp\Tools\CustomTools;

/**
 * The tools that the tools.* boot rules read (01-29), as PromptInstances for prompts (01-36). Built through the
 * container on each call, as McpServer builds them: a throw becomes the rule's "could not run" error, so the endpoint
 * stays off. A tool constructor reads nothing (the stub says so), so this stays a config-only check (spec 5.11).
 */
final class ToolInstances
{
    public function __construct(
        private readonly Container $container,
        private readonly PluginRegistry $plugins,
        private readonly CustomTools $custom,
    ) {}

    /** @return list<RuntimeTool> the active plugins' tools, then the custom ones that register */
    public function all(): array
    {
        $tools = [];

        foreach ($this->plugins->tools() as $class) {
            $tools[] = $this->make($class);
        }

        return [...$tools, ...$this->custom()];
    }

    /** @return list<RuntimeTool> the entries of runtime-mcp.tools that register; tools.class reports the others */
    public function custom(): array
    {
        $tools = [];

        foreach ($this->custom->classes() as $class) {
            $tool = $this->make($class);

            // McpToolAdapter's constructor reads name() and description() on every MCP request, and tools/list reads
            // tier(), opensWorld() and schema() through toArray() for every listed tool, all outside any try. So the
            // boot builds what tools/list builds: a throw here turns the endpoint off, where at request time it would
            // fail every request (01-36 triage, m1). A name that is not valid is tools.name's issue: the adapter
            // refuses it, so it is not built here.
            if (preg_match(McpToolAdapter::NAME_PATTERN, $tool->name()) === 1) {
                (new McpToolAdapter($tool, $this->container))->toArray();
            }

            $tools[] = $tool;
        }

        return $tools;
    }

    private function make(string $class): RuntimeTool
    {
        $tool = $this->container->make($class);

        if (! $tool instanceof RuntimeTool) {
            throw new LogicException("[{$class}] is not a RuntimeTool.");
        }

        return $tool;
    }
}
