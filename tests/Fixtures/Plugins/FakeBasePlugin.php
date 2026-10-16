<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Plugins;

use SinaSaeedi\RuntimeMcp\Plugins\AbstractPlugin;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\FakePingTool;

/** On by default, no package, one tool. */
final class FakeBasePlugin extends AbstractPlugin
{
    public function name(): string
    {
        return 'fake_base';
    }

    public function description(): string
    {
        return 'A test plugin that is on by default.';
    }

    public function toolPrefix(): string
    {
        return 'fake_';
    }

    public function enabledByDefault(): bool
    {
        return true;
    }

    public function tools(): array
    {
        return [FakePingTool::class];
    }
}
