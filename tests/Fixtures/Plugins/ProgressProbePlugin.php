<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Plugins;

use SinaSaeedi\RuntimeMcp\Plugins\AbstractPlugin;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\ProgressProbeTool;

/** On by default; registers 01-37's progress_probe tool. Separate from FixtureToolsPlugin, whose list McpToolAdapterTest pins. */
final class ProgressProbePlugin extends AbstractPlugin
{
    public function name(): string
    {
        return 'progress_probe';
    }

    public function description(): string
    {
        return 'A test plugin with the progress fixture tool.';
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
        return [ProgressProbeTool::class];
    }
}
