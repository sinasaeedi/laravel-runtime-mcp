<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Plugins;

use SinaSaeedi\RuntimeMcp\Plugins\AbstractPlugin;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\EchoTool;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\NoteLessTool;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\ResultTool;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\SecondEchoTool;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\SecretTool;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\ThrowingTool;

/** On by default; registers the pipeline fixture tools (01-08). */
final class FixtureToolsPlugin extends AbstractPlugin
{
    public function name(): string
    {
        return 'fixture_tools';
    }

    public function description(): string
    {
        return 'A test plugin with the guard pipeline fixture tools.';
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
        return [
            EchoTool::class,
            SecondEchoTool::class,
            SecretTool::class,
            ThrowingTool::class,
            NoteLessTool::class,
            ResultTool::class,
        ];
    }
}
