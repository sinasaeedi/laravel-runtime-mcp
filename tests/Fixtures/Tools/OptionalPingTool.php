<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use SinaSaeedi\RuntimeMcp\Tools\Contracts\RuntimeTool;
use SinaSaeedi\RuntimeMcp\Tools\Tier;
use SinaSaeedi\RuntimeMcp\Tools\ToolInput;
use SinaSaeedi\RuntimeMcp\Tools\ToolResult;

/** The tool of a plugin whose package is missing (01-03). */
final class OptionalPingTool extends RuntimeTool
{
    public function name(): string
    {
        return 'optional_ping';
    }

    public function description(): string
    {
        return 'A test tool.';
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
        return ToolResult::ok(['reply' => 'optional']);
    }
}
