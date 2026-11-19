<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\Custom;

use RuntimeException;

/** 01-29: a custom tool whose constructor does I/O and fails: the boot rules report it, no request builds it. */
final class ThrowingConstructorTool extends CustomFixtureTool
{
    public function __construct()
    {
        throw new RuntimeException('The ERP is down: rmcp_secret_in_a_message');
    }

    public function name(): string
    {
        return 'throwing_constructor';
    }
}
