<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Plugins;

use RuntimeException;
use SinaSaeedi\RuntimeMcp\Plugins\AbstractPlugin;

/** On by default; bootRules() itself throws. */
final class FakeThrowingRulesPlugin extends AbstractPlugin
{
    public function name(): string
    {
        return 'fake_throwing_rules';
    }

    public function description(): string
    {
        return 'A test plugin whose bootRules() throws.';
    }

    public function toolPrefix(): string
    {
        return 'throwing_rules_';
    }

    public function enabledByDefault(): bool
    {
        return true;
    }

    public function bootRules(): array
    {
        throw new RuntimeException('The plugin cannot list its rules.');
    }
}
