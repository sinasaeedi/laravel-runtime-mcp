<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Support;

use SinaSaeedi\RuntimeMcp\Tools\ProgressClock;

/**
 * 01-37: a ProgressClock for tests. Each now() steps the time by $step nanoseconds, or returns the next of $times
 * (then keeps the last one). Bind it with ProgressStreams::clock(); never sleep() in a test.
 */
final class SteppingClock extends ProgressClock
{
    private int $now = 0;

    /** @param  list<int>  $times */
    public function __construct(private readonly int $step = 1_000_000_000, private array $times = []) {}

    public function now(): int
    {
        if ($this->times !== []) {
            return $this->now = array_shift($this->times);
        }

        return $this->now += $this->step;
    }
}
