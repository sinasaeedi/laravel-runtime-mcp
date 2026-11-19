<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\Custom;

use RuntimeException;

/** 01-29: a custom tool whose description() throws. McpToolAdapter's constructor reads it on every MCP request, outside any try. */
final class ThrowingDescriptionTool extends CustomFixtureTool
{
    public function name(): string
    {
        return 'throwing_description';
    }

    public function description(): string
    {
        throw new RuntimeException('Broken.');
    }
}
