<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Plugins;

use SinaSaeedi\RuntimeMcp\Plugins\AbstractPlugin;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Boot\InvalidResultRule;

/** On by default; adds one boot rule whose check() returns a string, not a BootIssue. */
final class FakeInvalidRulesPlugin extends AbstractPlugin
{
    public function name(): string
    {
        return 'fake_invalid_rules';
    }

    public function description(): string
    {
        return 'A test plugin with a boot rule that returns an invalid result.';
    }

    public function toolPrefix(): string
    {
        return 'invalid_rules_';
    }

    public function enabledByDefault(): bool
    {
        return true;
    }

    public function bootRules(): array
    {
        return [new InvalidResultRule];
    }
}
