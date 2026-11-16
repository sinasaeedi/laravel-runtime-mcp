<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tools;

/**
 * The clock of FiberProgress's one-second rule (spec 5.15): a monotonic time in nanoseconds. Not final: the adapter
 * resolves it from the container, so a test binds a subclass that steps the time (never sleep() in a test).
 */
class ProgressClock
{
    public function now(): int
    {
        return hrtime(true);
    }
}
