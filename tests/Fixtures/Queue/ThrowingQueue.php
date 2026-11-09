<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Queue;

use RuntimeException;

/** A custom driver whose backend is down. The message holds a host, which the agent must never see. */
final class ThrowingQueue extends FixtureQueue
{
    public const MESSAGE = 'Connection refused: queue.internal.example:6379';

    public function size($queue = null)
    {
        throw new RuntimeException(self::MESSAGE);
    }
}
