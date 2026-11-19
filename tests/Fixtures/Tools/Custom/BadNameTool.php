<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\Custom;

/** 01-29: a custom tool whose name ends with a newline: only NAME_PATTERN's /D refuses it, and McpToolAdapter throws for it. */
final class BadNameTool extends CustomFixtureTool
{
    public function name(): string
    {
        return "erp_bad\n";
    }
}
