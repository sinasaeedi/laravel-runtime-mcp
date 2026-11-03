<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools;

use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use SinaSaeedi\RuntimeMcp\Tools\Contracts\RuntimeTool;
use SinaSaeedi\RuntimeMcp\Tools\Tier;
use SinaSaeedi\RuntimeMcp\Tools\ToolInput;
use SinaSaeedi\RuntimeMcp\Tools\ToolResult;

/**
 * A tool whose name, schema and rules a test chooses, for the tool contract helper (01-18). Built
 * with new, never through the container.
 */
final class ContractTool extends RuntimeTool
{
    /**
     * @param  Closure(JsonSchema): array<string, mixed>  $schema
     * @param  array<string, mixed>  $rules
     */
    public function __construct(
        private readonly string $name,
        private readonly Closure $schema,
        private readonly array $rules,
        private readonly bool $opensWorld = false,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return 'A test tool for the contract helper.';
    }

    public function tier(): Tier
    {
        return Tier::Read;
    }

    public function opensWorld(): bool
    {
        return $this->opensWorld;
    }

    public function schema(JsonSchema $schema): array
    {
        return ($this->schema)($schema);
    }

    public function rules(): array
    {
        return $this->rules;
    }

    public function execute(ToolInput $input): ToolResult
    {
        return ToolResult::ok([]);
    }
}
