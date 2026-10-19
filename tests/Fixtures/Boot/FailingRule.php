<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Boot;

use SinaSaeedi\RuntimeMcp\Security\Boot\BootIssue;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootRule;

/** Always one error. */
final class FailingRule implements BootRule
{
    public const ID = 'fixture.failing';

    public const MESSAGE = 'The fixture rule fails.';

    public function id(): string
    {
        return self::ID;
    }

    public function check(): array
    {
        return [BootIssue::error(self::ID, self::MESSAGE)];
    }
}
