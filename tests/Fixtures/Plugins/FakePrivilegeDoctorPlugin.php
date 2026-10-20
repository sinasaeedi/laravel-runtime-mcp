<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Plugins;

use SinaSaeedi\RuntimeMcp\Doctor\CheckResult;
use SinaSaeedi\RuntimeMcp\Doctor\CheckStatus;
use SinaSaeedi\RuntimeMcp\Plugins\AbstractPlugin;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Doctor\StaticCheck;

/** On by default; one failed privilege result, as 01-13's checks give (spec 4.6, third row). */
final class FakePrivilegeDoctorPlugin extends AbstractPlugin
{
    public function name(): string
    {
        return 'fake_privilege';
    }

    public function description(): string
    {
        return 'A test plugin with a failed privilege check.';
    }

    public function toolPrefix(): string
    {
        return 'privilege_';
    }

    public function enabledByDefault(): bool
    {
        return true;
    }

    public function doctorChecks(): array
    {
        return [new StaticCheck('Fake privileges', [
            new CheckResult(CheckStatus::Fail, 'the role can write to orders', 'REVOKE INSERT ON orders', privilege: true),
        ])];
    }
}
