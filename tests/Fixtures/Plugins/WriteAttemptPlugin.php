<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Plugins;

use SinaSaeedi\RuntimeMcp\Plugins\AbstractPlugin;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\WriteAttemptTool;

/** On by default; registers 01-16's write_attempt tool. Separate from FixtureToolsPlugin, whose list McpToolAdapterTest pins. */
final class WriteAttemptPlugin extends AbstractPlugin
{
    public function name(): string
    {
        return 'write_attempts';
    }

    public function description(): string
    {
        return 'A test plugin with the read-only transaction fixture tool.';
    }

    public function toolPrefix(): string
    {
        return '';
    }

    public function enabledByDefault(): bool
    {
        return true;
    }

    public function tools(): array
    {
        return [WriteAttemptTool::class];
    }
}
