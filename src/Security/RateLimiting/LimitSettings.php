<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\RateLimiting;

use Illuminate\Contracts\Config\Repository;
use LogicException;

/**
 * Spec 4.5, 5.8, 01-17 Part A: the rate_limits config, read on every call (rule 14). A bad value never
 * means "no limit": it gives the spec default, and doctor fails (problems()).
 */
final class LimitSettings
{
    public const DEFAULT_PER_PRINCIPAL = '120,1';

    /** The spec's per-tool defaults (spec 4.5). */
    public const DEFAULT_PER_TOOL = ['db_query' => '60,1'];

    /** A listed tool with a bad value and no spec default gets this: the only per-tool default in the spec. */
    public const FALLBACK_PER_TOOL = '60,1';

    /** The tool name rule of spec 4.3 (McpToolAdapter::NAME_PATTERN). A key that breaks it names no tool. */
    public const TOOL_NAME = '/^[a-z][a-z0-9_]{0,63}$/D';

    public const BAD_PER_PRINCIPAL = "rate_limits.per_principal is not 'attempts,minutes', so ".self::DEFAULT_PER_PRINCIPAL.' is used.';

    public const BAD_PER_TOOL_LIST = "rate_limits.per_tool is not a list of tool => 'attempts,minutes', so the spec default (db_query 60,1) is used.";

    public const BAD_TOOL_VALUE = "rate_limits.per_tool.%s is not 'attempts,minutes', so %s is used.";

    public const BAD_TOOL_NAME = 'An entry of rate_limits.per_tool does not name a tool (lower snake_case), so it limits nothing.';

    public function __construct(private readonly Repository $config) {}

    public function perPrincipal(): LimitSpec
    {
        return LimitSpec::parse($this->config->get('runtime-mcp.rate_limits.per_principal'))
            ?? self::spec(self::DEFAULT_PER_PRINCIPAL);
    }

    /** Null when the tool has no per-tool limit (an unlisted tool). */
    public function forTool(string $tool): ?LimitSpec
    {
        $perTool = $this->perTool();

        if (! array_key_exists($tool, $perTool)) {
            return null;
        }

        return LimitSpec::parse($perTool[$tool]) ?? self::spec(self::DEFAULT_PER_TOOL[$tool] ?? self::FALLBACK_PER_TOOL);
    }

    /**
     * Every configured per-tool limit in use, by tool name, in config order.
     *
     * @return array<string, LimitSpec>
     */
    public function perToolInUse(): array
    {
        $limits = [];

        foreach (array_keys($this->perTool()) as $tool) {
            if (is_string($tool) && preg_match(self::TOOL_NAME, $tool) === 1) {
                $limits[$tool] = $this->forTool($tool) ?? throw new LogicException("[{$tool}] is listed but has no limit.");
            }
        }

        return $limits;
    }

    /**
     * One sentence per bad key, for doctor. A key path is printed only when the tool name has the shape
     * of a name, and a bad value never (Sina, 2026-10-20).
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];

        if (LimitSpec::parse($this->config->get('runtime-mcp.rate_limits.per_principal')) === null) {
            $problems[] = self::BAD_PER_PRINCIPAL;
        }

        $raw = $this->config->get('runtime-mcp.rate_limits.per_tool');

        if ($raw !== null && ! is_array($raw)) {
            $problems[] = self::BAD_PER_TOOL_LIST;

            return $problems;
        }

        foreach (is_array($raw) ? $raw : [] as $tool => $value) {
            if (! is_string($tool) || preg_match(self::TOOL_NAME, $tool) !== 1) {
                $problems[] = self::BAD_TOOL_NAME;
            } elseif (LimitSpec::parse($value) === null) {
                $problems[] = sprintf(self::BAD_TOOL_VALUE, $tool, self::DEFAULT_PER_TOOL[$tool] ?? self::FALLBACK_PER_TOOL);
            }
        }

        return $problems;
    }

    /**
     * The per_tool list: missing or not an array gives the spec default; [] means no per-tool limit.
     *
     * @return array<array-key, mixed>
     */
    private function perTool(): array
    {
        $raw = $this->config->get('runtime-mcp.rate_limits.per_tool');

        return is_array($raw) ? $raw : self::DEFAULT_PER_TOOL;
    }

    private static function spec(string $value): LimitSpec
    {
        return LimitSpec::parse($value) ?? throw new LogicException("The default limit [{$value}] is not valid.");
    }
}
