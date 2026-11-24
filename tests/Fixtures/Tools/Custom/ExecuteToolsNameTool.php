<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\Custom;

/** 01-40: a custom tool named like a laravel/mcp catalog meta-tool: tools.reserved fails it. */
final class ExecuteToolsNameTool extends CustomFixtureTool
{
    public function name(): string
    {
        return 'execute_tools';
    }
}
