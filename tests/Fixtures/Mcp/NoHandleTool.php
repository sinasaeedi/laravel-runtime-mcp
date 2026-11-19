<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Mcp;

use Laravel\Mcp\Server\Tool;

/** 01-38: laravel/mcp's Tool declares no handle(), so a subclass can forget it. */
final class NoHandleTool extends Tool {}
