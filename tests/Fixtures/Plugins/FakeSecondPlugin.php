<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Plugins;

use SinaSaeedi\RuntimeMcp\Plugins\AbstractPlugin;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\SecondPingTool;

/** On by default, no package, one tool: proves the registry order. */
final class FakeSecondPlugin extends AbstractPlugin
{
    public function name(): string
    {
        return 'fake_second';
    }

    public function description(): string
    {
        return 'A second test plugin that is on by default.';
    }

    public function toolPrefix(): string
    {
        return 'second_';
    }

    public function enabledByDefault(): bool
    {
        return true;
    }

    public function tools(): array
    {
        return [SecondPingTool::class];
    }
}
