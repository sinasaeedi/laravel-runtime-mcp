<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\Custom;

/** 01-40: a custom tool named like a laravel/mcp catalog meta-tool: tools.reserved fails it. */
final class SearchToolsNameTool extends CustomFixtureTool
{
    public function name(): string
    {
        return 'search_tools';
    }
}
