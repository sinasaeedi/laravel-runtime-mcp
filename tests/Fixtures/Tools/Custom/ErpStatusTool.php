<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\Custom;

/** 01-29: a second custom tool, with no external system: its openWorldHint is false. */
final class ErpStatusTool extends CustomFixtureTool
{
    public function name(): string
    {
        return 'erp_status';
    }
}
