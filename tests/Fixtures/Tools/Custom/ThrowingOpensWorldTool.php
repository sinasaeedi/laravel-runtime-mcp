<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\Custom;

use RuntimeException;

/** 01-29: a custom tool whose opensWorld() throws. tools/list reads it through annotations() for every listed tool, outside any try. */
final class ThrowingOpensWorldTool extends CustomFixtureTool
{
    public function name(): string
    {
        return 'throwing_opens_world';
    }

    public function opensWorld(): bool
    {
        throw new RuntimeException('Broken.');
    }
}
