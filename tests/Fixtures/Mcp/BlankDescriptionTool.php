<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Mcp;

use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

/** 01-38: a description of spaces only. */
#[Description('  ')]
final class BlankDescriptionTool extends Tool
{
    public function handle(): Response
    {
        return Response::text('blank');
    }
}
