<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\Custom;

/** 01-29: a custom tool whose name clashes with the built-in db_query: tools.unique fails it. */
final class DbQueryNameTool extends CustomFixtureTool
{
    public function name(): string
    {
        return 'db_query';
    }
}
