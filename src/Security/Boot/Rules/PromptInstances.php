<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Boot\Rules;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use LogicException;
use ReflectionClass;
use SinaSaeedi\RuntimeMcp\Plugins\PluginRegistry;
use SinaSaeedi\RuntimeMcp\Prompts\Contracts\RuntimePrompt;
use SinaSaeedi\RuntimeMcp\Server\RuntimeServer;
use SinaSaeedi\RuntimeMcp\Tools\Contracts\RuntimeTool;

/**
 * The prompts and tools that the prompts.* boot rules read (01-36). Built through the container on each
 * call, as McpServer builds them (01-10's note: a throw becomes the rule's "could not run" error). Their
 * constructors read nothing, so this stays a config-only check (spec 5.11).
 */
final class PromptInstances
{
    public function __construct(
        private readonly Container $container,
        private readonly Repository $config,
        private readonly PluginRegistry $plugins,
        private readonly RuntimeServer $server,
    ) {}

    /**
     * An entry of runtime-mcp.prompts that the server can build: a concrete RuntimePrompt class.
     *
     * @phpstan-assert-if-true class-string<RuntimePrompt> $class
     */
    public static function isPromptClass(mixed $class): bool
    {
        return is_string($class)
            && is_subclass_of($class, RuntimePrompt::class)
            && (new ReflectionClass($class))->isInstantiable();
    }

    /** @return list<RuntimePrompt> the active plugins' prompts, then the valid custom ones */
    public function all(): array
    {
        $prompts = [];

        foreach ($this->plugins->prompts() as $class) {
            $prompts[] = $this->build($class);
        }

        return [...$prompts, ...$this->custom()];
    }

    /** @return list<RuntimePrompt> the valid entries of runtime-mcp.prompts; prompts.class reports the others */
    public function custom(): array
    {
        $entries = $this->config->get('runtime-mcp.prompts');
        $prompts = [];

        if (! is_array($entries) || ! array_is_list($entries)) {
            return [];
        }

        foreach ($entries as $class) {
            if (self::isPromptClass($class)) {
                $prompts[] = $this->build($class);
            }
        }

        return $prompts;
    }

    /** @return list<string> the names of the registered tools (the active plugins') */
    public function toolNames(): array
    {
        $names = [];

        foreach ($this->server->tools() as $class) {
            $tool = $this->container->make($class);

            if ($tool instanceof RuntimeTool) {
                $names[] = $tool->name();
            }
        }

        return $names;
    }

    private function build(string $class): RuntimePrompt
    {
        $prompt = $this->container->make($class);

        if (! $prompt instanceof RuntimePrompt) {
            throw new LogicException("[{$class}] is not a RuntimePrompt.");
        }

        // McpPromptAdapter's constructor reads both on every request, outside any try: a throw here is the
        // rule's "could not run" error, so the endpoint stays off instead of failing every MCP request.
        $prompt->title();
        $prompt->description();

        return $prompt;
    }
}
