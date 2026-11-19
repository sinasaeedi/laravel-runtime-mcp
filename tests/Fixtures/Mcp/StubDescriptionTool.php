<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Mcp;

use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

/** 01-38: the description that laravel/mcp's mcp-tool.stub writes, never changed. */
#[Description('A description of what this tool does.')]
final class StubDescriptionTool extends Tool
{
    public function handle(): Response
    {
        return Response::text('stub');
    }
}
