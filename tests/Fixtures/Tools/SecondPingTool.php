<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use SinaSaeedi\RuntimeMcp\Tools\Contracts\RuntimeTool;
use SinaSaeedi\RuntimeMcp\Tools\Tier;
use SinaSaeedi\RuntimeMcp\Tools\ToolInput;
use SinaSaeedi\RuntimeMcp\Tools\ToolResult;

/** A second tool with no arguments, for the registration tests (01-03). */
final class SecondPingTool extends RuntimeTool
{
    public function name(): string
    {
        return 'second_ping';
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
        return ToolResult::ok(['reply' => 'pong']);
    }
}
