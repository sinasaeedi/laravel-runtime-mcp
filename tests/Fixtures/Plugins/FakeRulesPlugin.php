<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Plugins;

use SinaSaeedi\RuntimeMcp\Plugins\AbstractPlugin;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Boot\FailingRule;

/** On by default; adds one boot rule that fails. */
final class FakeRulesPlugin extends AbstractPlugin
{
    public function name(): string
    {
        return 'fake_rules';
    }

    public function description(): string
    {
        return 'A test plugin with a boot rule that fails.';
    }

    public function toolPrefix(): string
    {
        return 'rules_';
    }

    public function enabledByDefault(): bool
    {
        return true;
    }

    public function bootRules(): array
    {
        return [new FailingRule];
    }
}
