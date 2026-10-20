<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Plugins;

use SinaSaeedi\RuntimeMcp\Doctor\CheckResult;
use SinaSaeedi\RuntimeMcp\Plugins\AbstractPlugin;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Doctor\StaticCheck;
use SinaSaeedi\RuntimeMcp\Tests\TestCase;

/** On by default; its doctor check holds a key in its message and its fix, as a careless check could. */
final class FakeLeakyDoctorPlugin extends AbstractPlugin
{
    public function name(): string
    {
        return 'fake_leaky';
    }

    public function description(): string
    {
        return 'A test plugin whose doctor check prints a key.';
    }

    public function toolPrefix(): string
    {
        return 'leaky_';
    }

    public function enabledByDefault(): bool
    {
        return true;
    }

    public function doctorChecks(): array
    {
        return [new StaticCheck('Leaky check', [
            CheckResult::warn('the check found '.TestCase::TEST_KEY, 'remove '.TestCase::TEST_KEY),
        ])];
    }
}
