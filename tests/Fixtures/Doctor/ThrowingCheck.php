<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Doctor;

use RuntimeException;
use SinaSaeedi\RuntimeMcp\Doctor\Check;

/** A check with a bug: run() throws, with a message that must never reach the report. */
final class ThrowingCheck implements Check
{
    public const SECRET = 'secret-in-the-exception-message';

    public function title(): string
    {
        return 'Throwing check';
    }

    public function run(): array
    {
        throw new RuntimeException(self::SECRET);
    }
}
