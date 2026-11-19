<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Mcp;

use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/** 01-38: an abstract laravel/mcp tool: the container cannot build it. */
abstract class AbstractLookupTool extends Tool
{
    public function handle(): Response
    {
        return Response::text('abstract');
    }
}
