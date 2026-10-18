<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tools\Contracts;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use SinaSaeedi\RuntimeMcp\Tools\Tier;
use SinaSaeedi\RuntimeMcp\Tools\ToolInput;
use SinaSaeedi\RuntimeMcp\Tools\ToolResult;

/**
 * Spec 4.1, 4.3: the base class of every tool. It is not a laravel/mcp Tool: only McpToolAdapter
 * (src/Mcp/) is, so a tool can neither skip the guard pipeline nor register itself without it.
 *
 * No laravel/mcp import here (spec 4.1). JsonSchema is the illuminate/contracts interface.
 */
abstract class RuntimeTool
{
    /** Stable snake_case name with the plugin prefix: db_query, queue_failed_jobs_list. Public API. */
    abstract public function name(): string;

    /** Written for an LLM. States the input limits. (The untrusted-data sentence is appended for you.) */
    abstract public function description(): string;

    abstract public function tier(): Tier;

    /** True when the tool calls an external system (spec 4.3, 7.4). */
    public function opensWorld(): bool
    {
        return false;
    }

    /**
     * JSON schema for the client: types, max lengths, enums, max limits (spec 4.3).
     *
     * @return array<string, mixed>
     */
    abstract public function schema(JsonSchema $schema): array;

    /**
     * Laravel validation rules for the same arguments: the enforced copy of the schema. A top-level
     * argument that no rule key names is refused (RunTool).
     *
     * @return array<string, mixed>
     */
    abstract public function rules(): array;

    /** The tool's own work. Bound its own work (spec 4.3): row limits, byte limits, counts. */
    abstract public function execute(ToolInput $input): ToolResult;

    /** Spec 5.5: the Gate name of this tool (01-09). Public API. */
    final public function ability(): string
    {
        return 'runtime-mcp.'.$this->name();
    }
}
