<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tools;

use Illuminate\Contracts\Config\Repository;
use ReflectionClass;
use SinaSaeedi\RuntimeMcp\Plugins\PluginRegistry;
use SinaSaeedi\RuntimeMcp\Tools\Contracts\RuntimeTool;

/**
 * Spec 4.5, 7.4 (01-29): the team's own tools, listed in runtime-mcp.tools. Reads config and class metadata only and
 * builds no tool, so RuntimeServer can call it on every request. RuntimeServer registers the entries it accepts; the
 * tools.class boot rule reports the others, by position.
 *
 * An entry that a plugin of this package lists is refused (spec 4.2): a plugin's tools register only when the operator
 * turns the plugin on and its package is installed, and only then do the plugin's boot rules run.
 */
final class CustomTools
{
    /** The entry is not a string, not a class, not a RuntimeTool, or abstract. */
    public const NOT_A_TOOL = 'not_a_tool';

    /** The entry is a tool of a plugin of this package. */
    public const BUILT_IN = 'built_in';

    public function __construct(
        private readonly Repository $config,
        private readonly PluginRegistry $plugins,
    ) {}

    /**
     * The entries of runtime-mcp.tools: [] when the key is missing or null, null when its value is not a list.
     *
     * @return list<mixed>|null
     */
    public function entries(): ?array
    {
        $entries = $this->config->get('runtime-mcp.tools');

        if ($entries === null) {
            return [];
        }

        return is_array($entries) && array_is_list($entries) ? $entries : null;
    }

    /**
     * Why each refused entry is refused, by its position from 0: NOT_A_TOOL or BUILT_IN.
     *
     * @return array<int, string>
     */
    public function refusals(): array
    {
        $builtIn = $this->builtIn();
        $refusals = [];

        foreach ($this->entries() ?? [] as $position => $class) {
            $refusal = self::refusal($class, $builtIn);

            if ($refusal !== null) {
                $refusals[$position] = $refusal;
            }
        }

        return $refusals;
    }

    /**
     * The entries that register, in config order. A class listed two times is kept two times: tools.unique fails it.
     *
     * @return list<class-string<RuntimeTool>>
     */
    public function classes(): array
    {
        $builtIn = $this->builtIn();
        $classes = [];

        foreach ($this->entries() ?? [] as $class) {
            if (self::isConcreteTool($class) && self::refusal($class, $builtIn) === null) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /** @param  array<string, true>  $builtIn */
    private static function refusal(mixed $class, array $builtIn): ?string
    {
        if (! self::isConcreteTool($class)) {
            return self::NOT_A_TOOL;
        }

        return isset($builtIn[self::key($class)]) ? self::BUILT_IN : null;
    }

    /** @phpstan-assert-if-true class-string<RuntimeTool> $class */
    private static function isConcreteTool(mixed $class): bool
    {
        return is_string($class)
            && is_subclass_of($class, RuntimeTool::class)
            && (new ReflectionClass($class))->isInstantiable();
    }

    /**
     * The tool classes of every plugin this version knows, on or off, by key().
     *
     * @return array<string, true>
     */
    private function builtIn(): array
    {
        $classes = [];

        foreach ($this->plugins->all() as $plugin) {
            foreach ($plugin->tools() as $class) {
                $classes[self::key($class)] = true;
            }
        }

        return $classes;
    }

    /** PHP class names ignore case, and a leading backslash names the same class. */
    private static function key(string $class): string
    {
        return strtolower(ltrim($class, '\\'));
    }
}
