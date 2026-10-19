<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Boot;

use SinaSaeedi\RuntimeMcp\Security\Boot\BootIssue;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootRule;

/** Always one warning. */
final class WarningRule implements BootRule
{
    public const ID = 'fixture.warning';

    public const MESSAGE = 'The fixture rule warns.';

    public function id(): string
    {
        return self::ID;
    }

    public function check(): array
    {
        return [BootIssue::warning(self::ID, self::MESSAGE)];
    }
}
