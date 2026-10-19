<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Boot;

use RuntimeException;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootRule;

/** Throws while it checks, with a message that must never reach the log. */
final class ThrowingRule implements BootRule
{
    public const ID = 'fixture.throwing';

    public const SECRET = 'password=hunter2';

    public function id(): string
    {
        return self::ID;
    }

    public function check(): array
    {
        throw new RuntimeException('Could not read '.self::SECRET);
    }
}
