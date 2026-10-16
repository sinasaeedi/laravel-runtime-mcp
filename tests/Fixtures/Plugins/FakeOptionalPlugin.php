<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Plugins;

use SinaSaeedi\RuntimeMcp\Plugins\AbstractPlugin;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\OptionalPingTool;

/** Off by default; its package is not installed. */
final class FakeOptionalPlugin extends AbstractPlugin
{
    public function name(): string
    {
        return 'fake_optional';
    }

    public function description(): string
    {
        return 'A test plugin that needs a package that is not installed.';
    }

    public function toolPrefix(): string
    {
        return 'optional_';
    }

    public function requiredPackage(): string
    {
        return 'acme/not-installed';
    }

    public function tools(): array
    {
        return [OptionalPingTool::class];
    }
}
