<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/** A plain laravel/mcp tool for the registration tests (01-03). 01-08 moves fixtures to RuntimeTool. */
final class SecondPingTool extends Tool
{
    protected string $name = 'second_ping';

    protected string $description = 'A test tool.';

    public function handle(Request $request): Response
    {
        return Response::text('second');
    }
}
