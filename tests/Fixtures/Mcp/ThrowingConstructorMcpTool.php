<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Mcp;

use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use RuntimeException;

/** 01-38: an official tool whose constructor throws, with a secret-like text that no boot message may show. */
#[Description('Throws when it is built.')]
final class ThrowingConstructorMcpTool extends Tool
{
    public function __construct()
    {
        throw new RuntimeException('rmcp_secret is not set');
    }

    public function handle(): Response
    {
        return Response::text('never');
    }
}
