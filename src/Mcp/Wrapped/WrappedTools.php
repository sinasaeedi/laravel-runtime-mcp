<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Mcp\Wrapped;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Laravel\Mcp\Server\Attributes\RendersApp;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\ExecuteTools;
use Laravel\Mcp\Server\Tools\SearchTools;
use LogicException;
use ReflectionClass;
use SinaSaeedi\RuntimeMcp\Mcp\McpToolAdapter;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootIssue;
use SinaSaeedi\RuntimeMcp\Tools\Tier;

/**
 * Spec 4.5, 5.16 (01-38): the entries of runtime-mcp.wrapped_tools. entries() and issues() read config and class
 * metadata only, so the boot rules and every request can call them; build() makes one WrappedTool per valid entry,
 * in config order, for McpToolAdapters, ToolInstances and PromptInstances. An entry that fails a rule is left out;
 * the wrapped_tools.* boot rules report it, by position only (a config value can be any text).
 */
final class WrappedTools
{
    public const ENTRY = 'wrapped_tools.entry';

    public const CLASS_RULE = 'wrapped_tools.class';

    public const NAME = 'wrapped_tools.name';

    public const TIER = 'wrapped_tools.tier';

    public const OPEN_WORLD = 'wrapped_tools.open_world';

    public const RENDERS_APP = 'wrapped_tools.renders_app';

    /** The keys of an entry, exactly these (spec 5.16). */
    public const KEYS = ['class', 'name', 'tier', 'open_world'];

    /** Tool classes that are laravel/mcp's own or ours: never wrapped. */
    public const OWN = [SearchTools::class, ExecuteTools::class, McpToolAdapter::class];

    public const NOT_A_LIST = 'runtime-mcp.wrapped_tools is not a list of entries.';

    /** %d is the position, from 0, in each message below. */
    public const BAD_ENTRY = 'Entry %d of runtime-mcp.wrapped_tools is not an array with exactly the keys class, name, tier and open_world.';

    public const NOT_A_TOOL = 'Entry %d of runtime-mcp.wrapped_tools is not a concrete class that extends Laravel\Mcp\Server\Tool and has a handle() method.';

    public const OWN_TOOL = 'Entry %d of runtime-mcp.wrapped_tools is a tool of laravel/mcp or of this package, which cannot be wrapped.';

    public const BAD_NAME = 'Entry %d of runtime-mcp.wrapped_tools has a name that is not lower snake case of at most 64 characters.';

    public const BAD_TIER = 'Entry %d of runtime-mcp.wrapped_tools has a tier that is not read or read_sensitive.';

    public const WRITE_TIER = 'Entry %d of runtime-mcp.wrapped_tools asks for write_safe. Write tools arrive in v1.13.0, with a human approval for every call.';

    public const BAD_OPEN_WORLD = 'Entry %d of runtime-mcp.wrapped_tools has an open_world that is not true or false.';

    public const RENDERS_APP_TOOL = 'Entry %d of runtime-mcp.wrapped_tools renders an MCP App. MCP Apps are not supported (spec rule 19).';

    public function __construct(
        private readonly Repository $config,
        private readonly Container $container,
    ) {}

    /**
     * The entries: [] when the key is missing or null, null when its value is not a list.
     *
     * @return list<mixed>|null
     */
    public function entries(): ?array
    {
        $entries = $this->config->get('runtime-mcp.wrapped_tools');

        if ($entries === null) {
            return [];
        }

        return is_array($entries) && array_is_list($entries) ? $entries : null;
    }

    /**
     * The errors of one wrapped_tools.* rule, by position.
     *
     * @return list<BootIssue>
     */
    public function issues(string $ruleId): array
    {
        $entries = $this->entries();

        if ($entries === null) {
            return $ruleId === self::ENTRY ? [BootIssue::error(self::ENTRY, self::NOT_A_LIST)] : [];
        }

        $issues = [];

        foreach ($entries as $position => $entry) {
            $message = self::check($entry)[0][$ruleId] ?? null;

            if ($message !== null) {
                $issues[] = BootIssue::error($ruleId, sprintf($message, $position));
            }
        }

        return $issues;
    }

    /**
     * One WrappedTool per valid entry, in config order. The official tool is built through the container; a throw is
     * not caught (at boot it is the rule's "could not run" error, so the endpoint stays off).
     *
     * @return list<WrappedTool>
     */
    public function build(): array
    {
        $tools = [];

        foreach ($this->entries() ?? [] as $entry) {
            $valid = self::check($entry)[1];

            if ($valid === null) {
                continue;
            }

            [$class, $name, $tier, $openWorld] = $valid;
            $tool = $this->container->make($class);

            if (! $tool instanceof Tool) {
                throw new LogicException("[{$class}] is not a laravel/mcp Tool.");
            }

            $tools[] = new WrappedTool($tool, $name, $tier, $openWorld, $this->container);
        }

        return $tools;
    }

    /**
     * The failed rules of one entry (rule id => message with %d), and the entry's values when no rule failed. An entry
     * that is not a valid array fails wrapped_tools.entry only: its other keys cannot be read.
     *
     * @return array{array<string, string>, array{class-string<Tool>, string, Tier, bool}|null}
     */
    private static function check(mixed $entry): array
    {
        if (! is_array($entry) || count($entry) !== count(self::KEYS) || array_diff(self::KEYS, array_keys($entry)) !== []) {
            return [[self::ENTRY => self::BAD_ENTRY], null];
        }

        $problems = [];
        $class = $entry['class'];
        $name = $entry['name'];
        $tier = is_string($entry['tier']) ? Tier::tryFrom($entry['tier']) : null;
        $openWorld = $entry['open_world'];

        if (! is_string($class) || ! self::isConcreteTool($class)) {
            $problems[self::CLASS_RULE] = self::NOT_A_TOOL;
        } elseif (in_array(self::key($class), array_map(self::key(...), self::OWN), true)) {
            $problems[self::CLASS_RULE] = self::OWN_TOOL;
        } elseif (self::rendersApp($class)) {
            $problems[self::RENDERS_APP] = self::RENDERS_APP_TOOL;
        }

        if (! is_string($name) || preg_match(McpToolAdapter::NAME_PATTERN, $name) !== 1) {
            $problems[self::NAME] = self::BAD_NAME;
        }

        if ($tier === null) {
            $problems[self::TIER] = $entry['tier'] === 'write_safe' ? self::WRITE_TIER : self::BAD_TIER;
        }

        if (! is_bool($openWorld)) {
            $problems[self::OPEN_WORLD] = self::BAD_OPEN_WORLD;
        }

        if ($problems !== [] || ! is_string($class) || ! is_subclass_of($class, Tool::class) || ! is_string($name) || $tier === null || ! is_bool($openWorld)) {
            return [$problems, null];
        }

        return [$problems, [$class, $name, $tier, $openWorld]];
    }

    /** @phpstan-assert-if-true class-string<Tool> $class */
    private static function isConcreteTool(string $class): bool
    {
        if (! is_subclass_of($class, Tool::class)) {
            return false;
        }

        $reflection = new ReflectionClass($class);

        // A handle() that is not public is never callable by WrappedTool::execute() (01-38 triage m2).
        return $reflection->isInstantiable()
            && $reflection->hasMethod('handle')
            && $reflection->getMethod('handle')->isPublic();
    }

    /** #[RendersApp] on the class or on a parent class: laravel/mcp's ReadsAttributes reads parents too. */
    /** @param  class-string  $class */
    private static function rendersApp(string $class): bool
    {
        for ($reflection = new ReflectionClass($class); $reflection !== false; $reflection = $reflection->getParentClass()) {
            if ($reflection->getAttributes(RendersApp::class) !== []) {
                return true;
            }
        }

        return false;
    }

    /** PHP class names ignore case, and a leading backslash names the same class. */
    private static function key(string $class): string
    {
        return strtolower(ltrim($class, '\\'));
    }
}
