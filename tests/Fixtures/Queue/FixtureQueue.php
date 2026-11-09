<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Queue;

use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\Queue;

/**
 * A custom queue driver for 01-22 (Queue::extend()): it is no SyncQueue, NullQueue or DatabaseQueue, so
 * queue_overview calls its size(). It holds no jobs. Laravel 13 adds the four *Size methods to the contract.
 */
abstract class FixtureQueue extends Queue implements QueueContract
{
    abstract public function size($queue = null);

    public function pendingSize($queue = null)
    {
        return 0;
    }

    public function delayedSize($queue = null)
    {
        return 0;
    }

    public function reservedSize($queue = null)
    {
        return 0;
    }

    public function creationTimeOfOldestPendingJob($queue = null)
    {
        return null;
    }

    public function push($job, $data = '', $queue = null)
    {
        return null;
    }

    /** @param  array<string, mixed>  $options */
    public function pushRaw($payload, $queue = null, array $options = [])
    {
        return null;
    }

    public function later($delay, $job, $data = '', $queue = null)
    {
        return null;
    }

    public function pop($queue = null)
    {
        return null;
    }
}
