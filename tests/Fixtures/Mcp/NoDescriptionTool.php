<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Mcp;

use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/** 01-38: no #[Description]: Primitive::description() falls back to "No Description Tool". */
final class NoDescriptionTool extends Tool
{
    public function handle(): Response
    {
        return Response::text('no description');
    }
}
