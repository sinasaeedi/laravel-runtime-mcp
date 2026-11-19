<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Mcp;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

/** 01-38 triage m2: a handle() that is not public. method_exists() sees it; nothing outside the class can call it. */
#[Description('Never callable from outside: its handle() is protected.')]
final class ProtectedHandleTool extends Tool
{
    protected function handle(Request $request): Response
    {
        return Response::text('never');
    }
}
