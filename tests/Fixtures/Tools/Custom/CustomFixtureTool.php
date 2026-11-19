<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\Custom;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use SinaSaeedi\RuntimeMcp\Tools\Contracts\RuntimeTool;
use SinaSaeedi\RuntimeMcp\Tools\Tier;
use SinaSaeedi\RuntimeMcp\Tools\ToolInput;
use SinaSaeedi\RuntimeMcp\Tools\ToolResult;

/**
 * 01-29: the base of the custom tool fixtures that only the boot rules read. It is abstract, so tools.class also
 * refuses it as an entry (not instantiable).
 */
abstract class CustomFixtureTool extends RuntimeTool
{
    public function description(): string
    {
        return 'A custom fixture tool. Takes no input.';
    }

    public function tier(): Tier
    {
        return Tier::Read;
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function rules(): array
    {
        return [];
    }

    public function execute(ToolInput $input): ToolResult
    {
        return ToolResult::ok(['tool' => $this->name()]);
    }
}
