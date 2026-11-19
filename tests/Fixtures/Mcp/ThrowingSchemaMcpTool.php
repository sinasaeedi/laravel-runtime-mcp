<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Mcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use RuntimeException;

/** 01-38: an official tool whose schema() throws: tools/list would fail on every request, so the boot must fail. */
#[Description('Throws when its schema is read.')]
final class ThrowingSchemaMcpTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        throw new RuntimeException('rmcp_secret schema');
    }

    public function handle(): Response
    {
        return Response::text('never');
    }
}
