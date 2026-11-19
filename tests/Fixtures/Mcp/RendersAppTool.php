<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Mcp;

use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\RendersApp;
use Laravel\Mcp\Server\Tool;

/** 01-38: an MCP App tool (rule 19). Not final: RendersAppChildTool inherits the attribute. */
#[Description('Shows an order in an app.')]
#[RendersApp(OrderLookupTool::class)]
class RendersAppTool extends Tool
{
    public function handle(): Response
    {
        return Response::text('app');
    }
}
