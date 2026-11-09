<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Queue;

/** A custom driver with a fixed size per queue name, and the queue names it was asked for. */
final class CountingQueue extends FixtureQueue
{
    /** @var list<string|null> */
    public array $asked = [];

    /** @param  array<string, int>  $sizes */
    public function __construct(private readonly array $sizes = []) {}

    public function size($queue = null)
    {
        $this->asked[] = is_string($queue) ? $queue : null;

        return $this->sizes[is_string($queue) ? $queue : ''] ?? 0;
    }
}
