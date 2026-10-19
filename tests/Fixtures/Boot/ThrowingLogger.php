<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Boot;

use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

/** A log channel that is broken (for example a full disk). */
final class ThrowingLogger extends AbstractLogger
{
    /** @param  array<array-key, mixed>  $context */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        throw new RuntimeException('The log is broken.');
    }
}
