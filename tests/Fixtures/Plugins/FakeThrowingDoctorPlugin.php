<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Plugins;

use RuntimeException;
use SinaSaeedi\RuntimeMcp\Plugins\AbstractPlugin;

/** On by default; doctorChecks() itself throws. */
final class FakeThrowingDoctorPlugin extends AbstractPlugin
{
    public function name(): string
    {
        return 'fake_throwing_doctor';
    }

    public function description(): string
    {
        return 'A test plugin whose doctorChecks() throws.';
    }

    public function toolPrefix(): string
    {
        return 'throwing_doctor_';
    }

    public function enabledByDefault(): bool
    {
        return true;
    }

    public function doctorChecks(): array
    {
        throw new RuntimeException('doctorChecks() is broken');
    }
}
