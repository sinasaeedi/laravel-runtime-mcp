<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\Custom;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use RuntimeException;

/** 01-29: a custom tool whose schema() throws. tools/list reads it through toArray() for every listed tool, outside any try. */
final class ThrowingSchemaTool extends CustomFixtureTool
{
    public function name(): string
    {
        return 'throwing_schema';
    }

    public function schema(JsonSchema $schema): array
    {
        throw new RuntimeException('Broken.');
    }
}
