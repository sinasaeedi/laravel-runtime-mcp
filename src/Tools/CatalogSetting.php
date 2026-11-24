<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tools;

use Illuminate\Contracts\Config\Repository;

/**
 * Spec 4.5, 5.8, 5.17 (01-40): catalog.enabled, and the two mcp.tool_search.* values that laravel/mcp's ToolSearch
 * reads. The one reader: McpServer, Methods\CallTool, OutputCap and the Catalog doctor check use it. Read on every call
 * (rule 14).
 *
 * The mcp.tool_search.* values are the app's config (spec 5.17): this class only reads them, through the config
 * repository (spec 4.1: no Laravel\Mcp import outside src/Mcp/), and computes them exactly as ToolSearch::__construct()
 * does, so the cap in use matches the batch limit that laravel/mcp applies.
 */
final class CatalogSetting
{
    /** ToolSearch's code defaults (laravel/mcp's published config file has max_tool_calls 10). */
    public const DEFAULT_MAX_OUTPUT_BYTES = 65_536;

    public const DEFAULT_MAX_TOOL_CALLS = 25;

    /** ToolSearch's floors: max(256, …) and max(1, …). */
    public const MIN_MAX_OUTPUT_BYTES = 256;

    public const MIN_MAX_TOOL_CALLS = 1;

    public function __construct(private readonly Repository $config) {}

    /** Only the bool true turns the catalog on: a typo never changes what the agent sees. */
    public function enabled(): bool
    {
        return $this->config->get('runtime-mcp.catalog.enabled') === true;
    }

    /** A bool, or null (a published config without the key, or an unset env()): both are off without a doctor Fail. */
    public function valueIsValid(): bool
    {
        $value = $this->config->get('runtime-mcp.catalog.enabled');

        return is_bool($value) || $value === null;
    }

    /** max(256, (int) mcp.tool_search.max_output_bytes), default 65,536, as ToolSearch::__construct(). */
    public function toolSearchMaxOutputBytes(): int
    {
        return max(self::MIN_MAX_OUTPUT_BYTES, self::toInt($this->config->get('mcp.tool_search.max_output_bytes', self::DEFAULT_MAX_OUTPUT_BYTES)));
    }

    /** max(1, (int) mcp.tool_search.max_tool_calls), default 25, as ToolSearch::__construct(). */
    public function toolSearchMaxToolCalls(): int
    {
        return max(self::MIN_MAX_TOOL_CALLS, self::toInt($this->config->get('mcp.tool_search.max_tool_calls', self::DEFAULT_MAX_TOOL_CALLS)));
    }

    /**
     * PHP's (int) cast, as ToolSearch applies it to any config value: "12abc" is 12, "abc" is 0, 70000.9 is 70000. A bool,
     * an array, null or an object casts to 0 or 1, which both floors raise to the same result, so it counts as 0 here
     * (and an object raises no warning).
     */
    private static function toInt(mixed $value): int
    {
        return is_int($value) || is_float($value) || is_string($value) ? (int) $value : 0;
    }
}
