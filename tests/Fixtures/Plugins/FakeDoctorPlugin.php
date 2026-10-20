<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Plugins;

use SinaSaeedi\RuntimeMcp\Doctor\CheckResult;
use SinaSaeedi\RuntimeMcp\Plugins\AbstractPlugin;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Doctor\StaticCheck;

/** On by default; adds one doctor check with a result of each status. */
final class FakeDoctorPlugin extends AbstractPlugin
{
    public function name(): string
    {
        return 'fake_doctor';
    }

    public function description(): string
    {
        return 'A test plugin with doctor checks.';
    }

    public function toolPrefix(): string
    {
        return 'doctor_';
    }

    public function enabledByDefault(): bool
    {
        return true;
    }

    public function doctorChecks(): array
    {
        return [new StaticCheck('Fake plugin check', [
            // A console tag in a message must print as text, never as a style.
            CheckResult::pass('plugin pass line <info>kept</info>'),
            CheckResult::warn('plugin warn line', 'plugin warn fix'),
            CheckResult::fail('plugin fail line', 'plugin fail fix'),
        ])];
    }
}
