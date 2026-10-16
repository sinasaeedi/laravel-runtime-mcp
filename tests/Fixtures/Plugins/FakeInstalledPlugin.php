<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Plugins;

use SinaSaeedi\RuntimeMcp\Plugins\AbstractPlugin;

/** Off by default; its package (a real require, not a dev one) is installed. */
final class FakeInstalledPlugin extends AbstractPlugin
{
    public function name(): string
    {
        return 'fake_installed';
    }

    public function description(): string
    {
        return 'A test plugin that needs a package that is installed.';
    }

    public function toolPrefix(): string
    {
        return 'installed_';
    }

    public function requiredPackage(): string
    {
        return 'laravel/mcp';
    }
}
