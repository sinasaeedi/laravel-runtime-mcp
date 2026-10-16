<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Plugins;

use SinaSaeedi\RuntimeMcp\Plugins\AbstractPlugin;
use stdClass;

/** On by default, and lists a class that is not a laravel/mcp tool. */
final class FakeBrokenPlugin extends AbstractPlugin
{
    public function name(): string
    {
        return 'fake_broken';
    }

    public function description(): string
    {
        return 'A test plugin that lists a class that is not a tool.';
    }

    public function toolPrefix(): string
    {
        return 'broken_';
    }

    public function enabledByDefault(): bool
    {
        return true;
    }

    public function tools(): array
    {
        return [stdClass::class];
    }
}
