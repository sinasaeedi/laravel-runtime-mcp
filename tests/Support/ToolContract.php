<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Support;

use Illuminate\JsonSchema\JsonSchema;
use PHPUnit\Framework\Assert;
use SinaSaeedi\RuntimeMcp\Mcp\McpToolAdapter;
use SinaSaeedi\RuntimeMcp\Plugins\Contracts\Plugin;
use SinaSaeedi\RuntimeMcp\Tools\Contracts\RuntimeTool;
use Stringable;

/**
 * The tool contract (spec 4.3), checked the same way for every tool from 01-18 on. Call it through
 * assertToolContract() in tests/Pest.php. A broken check fails with the tool name and the check.
 *
 * Keep every check in this one class: 01-39 moves it to src/Testing/ unchanged.
 */
final class ToolContract
{
    /** The schema keywords that the max: rule enforces (strings, numbers, arrays). */
    private const LIMITS = ['maxLength', 'maximum', 'maxItems'];

    /** @param  Plugin|null  $plugin  the tool's plugin, or null for a custom tool (01-29), which has no prefix */
    public static function assert(RuntimeTool $tool, ?Plugin $plugin = null): void
    {
        $name = $tool->name();

        if (preg_match(McpToolAdapter::NAME_PATTERN, $name) !== 1) {
            self::fail($name, 'the name does not match '.McpToolAdapter::NAME_PATTERN.'.');
        }

        if ($plugin !== null && ! str_starts_with($name, $plugin->toolPrefix())) {
            self::fail($name, "the name does not start with the prefix [{$plugin->toolPrefix()}] of the plugin [{$plugin->name()}].");
        }

        if ($tool->ability() !== 'runtime-mcp.'.$name) {
            self::fail($name, "the ability is [{$tool->ability()}], not [runtime-mcp.{$name}].");
        }

        self::assertAdapter($tool);
        self::assertSchemaMatchesRules($tool);
    }

    private static function assertAdapter(RuntimeTool $tool): void
    {
        $name = $tool->name();
        $adapter = new McpToolAdapter($tool, app());
        $suffix = "\n\n".McpToolAdapter::UNTRUSTED;

        if (! str_ends_with($adapter->description(), $suffix) || strlen($adapter->description()) <= strlen($suffix)) {
            self::fail($name, 'the description is empty, or it does not end with the untrusted-data sentence.');
        }

        $expected = ['readOnlyHint' => true, 'openWorldHint' => $tool->opensWorld()];

        if ($adapter->annotations() !== $expected) {
            self::fail($name, 'the annotations are '.self::json($adapter->annotations()).', not '.self::json($expected).'.');
        }
    }

    /**
     * Spec 4.3: the schema states the limits for the client, the rules enforce them. Both must say
     * the same thing, both ways (Q1 of the 01-18 audit).
     */
    private static function assertSchemaMatchesRules(RuntimeTool $tool): void
    {
        $name = $tool->name();
        $schema = JsonSchema::object($tool->schema(...))->toArray();
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $rules = [];

        foreach ($tool->rules() as $key => $value) {
            $rules[(string) $key] = self::ruleStrings($value);
        }

        $ruleRoots = [];

        foreach (array_keys($rules) as $key) {
            $ruleRoots[explode('.', $key, 2)[0]] = true;
        }

        foreach (array_keys($properties) as $property) {
            if (! isset($ruleRoots[(string) $property])) {
                self::fail($name, "the schema property [{$property}] has no rule.");
            }
        }

        // A schema node with a limit or an enum needs its own rule key: without one, the rules never
        // enforce what the schema tells the client ("with.*" for an items enum, "filters.status").
        $checks = $rules;

        foreach (self::constrainedKeys($properties) as $key) {
            $checks[$key] ??= [];
        }

        foreach ($checks as $key => $strings) {
            $root = explode('.', $key, 2)[0];

            if (! array_key_exists($root, $properties)) {
                self::fail($name, "the rule key [{$key}] names no schema property.");
            }

            $node = self::node($properties, $key);
            self::assertLimits($name, $key, $node, $strings);
            self::assertEnum($name, $key, $node, $strings);
        }
    }

    /**
     * @param  array<string, mixed>|null  $node
     * @param  list<string>  $strings
     */
    private static function assertLimits(string $name, string $key, ?array $node, array $strings): void
    {
        $schemaLimits = [];

        foreach (self::LIMITS as $keyword) {
            if (isset($node[$keyword]) && (is_int($node[$keyword]) || is_float($node[$keyword]))) {
                $schemaLimits[] = $node[$keyword];
            }
        }

        $ruleLimits = array_map(
            fn (string $parameter): float => (float) $parameter,
            self::parameters($strings, 'max'),
        );

        if ($schemaLimits === [] && $ruleLimits === []) {
            return;
        }

        if ($schemaLimits === []) {
            self::fail($name, "the rules of [{$key}] have max:".implode(',', self::parameters($strings, 'max')).', but the schema states no limit.');
        }

        if ($ruleLimits === []) {
            self::fail($name, "the schema limits [{$key}] to ".implode(',', $schemaLimits).', but its rules have no max: rule.');
        }

        foreach ($schemaLimits as $limit) {
            if (! in_array((float) $limit, $ruleLimits, true)) {
                self::fail($name, "the schema limits [{$key}] to {$limit}, but its rules say max:".implode(',', self::parameters($strings, 'max')).'.');
            }
        }

        foreach ($ruleLimits as $limit) {
            if (! in_array($limit, array_map(fn (int|float $value): float => (float) $value, $schemaLimits), true)) {
                self::fail($name, "the rules of [{$key}] say max:{$limit}, but the schema limits it to ".implode(',', $schemaLimits).'.');
            }
        }
    }

    /**
     * @param  array<string, mixed>|null  $node
     * @param  list<string>  $strings
     */
    private static function assertEnum(string $name, string $key, ?array $node, array $strings): void
    {
        $schemaValues = isset($node['enum']) && is_array($node['enum'])
            ? self::sorted(array_map(fn (mixed $value): string => is_scalar($value) ? (string) $value : self::json($value), $node['enum']))
            : null;

        $in = self::parameters($strings, 'in');
        $ruleValues = $in === [] ? null : self::sorted(str_getcsv($in[0], ',', '"', ''));

        if ($schemaValues === null && $ruleValues === null) {
            return;
        }

        if ($schemaValues === null) {
            self::fail($name, "the rules of [{$key}] have an in: rule, but the schema has no enum.");
        }

        if ($ruleValues === null) {
            self::fail($name, "the schema has an enum for [{$key}], but its rules have no in: rule.");
        }

        if ($schemaValues !== $ruleValues) {
            self::fail($name, "the schema enum of [{$key}] is ".self::json($schemaValues).', but its in: rule allows '.self::json($ruleValues).'.');
        }
    }

    /**
     * The schema node that a rule key describes: "with" is the property, "with.*" its items,
     * "filters.status" a property of the object "filters". Null when the schema has no such node.
     *
     * @param  array<array-key, mixed>  $properties
     * @return array<string, mixed>|null
     */
    private static function node(array $properties, string $key): ?array
    {
        $segments = explode('.', $key);
        $node = $properties[array_shift($segments)] ?? null;

        foreach ($segments as $segment) {
            if (! is_array($node)) {
                return null;
            }

            $children = $segment === '*' ? ['*' => $node['items'] ?? null] : ($node['properties'] ?? null);
            $node = is_array($children) ? ($children[$segment] ?? null) : null;
        }

        if (! is_array($node)) {
            return null;
        }

        /** @var array<string, mixed> $node */
        return $node;
    }

    /**
     * The rule key of every schema node, at any depth, that states a limit or an enum: "with",
     * "with.*" for its items, "filters.status" for a property of the object "filters".
     *
     * @param  array<array-key, mixed>  $properties
     * @return list<string>
     */
    private static function constrainedKeys(array $properties, string $prefix = ''): array
    {
        $keys = [];

        foreach ($properties as $property => $node) {
            $key = $prefix.$property;

            if (! is_array($node)) {
                continue;
            }

            foreach ([...self::LIMITS, 'enum'] as $keyword) {
                if (isset($node[$keyword])) {
                    $keys[] = $key;

                    break;
                }
            }

            if (is_array($node['items'] ?? null)) {
                $keys = [...$keys, ...self::constrainedKeys(['*' => $node['items']], $key.'.')];
            }

            if (is_array($node['properties'] ?? null)) {
                $keys = [...$keys, ...self::constrainedKeys($node['properties'], $key.'.')];
            }
        }

        return $keys;
    }

    /**
     * One rule set as a list of strings: a pipe string is split; an array keeps its strings and casts
     * Stringable objects (Rule::in() gives in:"a","b c"); closures and other rule objects are ignored.
     *
     * @return list<string>
     */
    private static function ruleStrings(mixed $rules): array
    {
        if (is_string($rules)) {
            return explode('|', $rules);
        }

        $strings = [];

        foreach (is_array($rules) ? $rules : [$rules] as $rule) {
            if (is_string($rule)) {
                $strings[] = $rule;
            } elseif ($rule instanceof Stringable || (is_object($rule) && method_exists($rule, '__toString'))) {
                $strings[] = (string) $rule;
            }
        }

        return $strings;
    }

    /**
     * The parameter text of each rule with this name: "max:20" gives "20", in:"a","b" gives "a","b".
     *
     * @param  list<string>  $strings
     * @return list<string>
     */
    private static function parameters(array $strings, string $rule): array
    {
        $parameters = [];

        foreach ($strings as $string) {
            [$ruleName, $parameter] = array_pad(explode(':', $string, 2), 2, '');

            if (strtolower(trim($ruleName)) === $rule) {
                $parameters[] = trim($parameter);
            }
        }

        return $parameters;
    }

    /**
     * @param  array<array-key, string|null>  $values
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        $values = array_map(fn (?string $value): string => (string) $value, array_values($values));
        sort($values, SORT_STRING);

        return $values;
    }

    private static function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function fail(string $name, string $check): never
    {
        Assert::fail("Tool [{$name}] breaks the tool contract: {$check}");
    }
}
