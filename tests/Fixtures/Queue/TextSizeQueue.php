<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Queue;

/** A custom driver that breaks the contract: its size() returns what it is given, not an int of 0 or more. */
final class TextSizeQueue extends FixtureQueue
{
    public function __construct(private readonly mixed $size) {}

    public function size($queue = null)
    {
        // @phpstan-ignore return.type (the bug this fixture stands for)
        return $this->size;
    }
}
