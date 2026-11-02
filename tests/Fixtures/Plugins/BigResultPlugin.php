<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Plugins;

use SinaSaeedi\RuntimeMcp\Plugins\AbstractPlugin;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\BigResultTool;

/** On by default; registers 01-17's big_result tool. Separate from FixtureToolsPlugin, whose list McpToolAdapterTest pins. */
final class BigResultPlugin extends AbstractPlugin
{
    public function name(): string
    {
        return 'big_results';
    }

    public function description(): string
    {
        return 'A test plugin with the output cap fixture tool.';
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
        return [BigResultTool::class];
    }
}
