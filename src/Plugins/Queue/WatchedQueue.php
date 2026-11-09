<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins\Queue;

/**
 * One (connection, queue) pair of runtime-mcp.queue.names (01-22 Part A), or the reason it cannot be watched.
 * Every name here has the shape of a name: one that does not is null, and the problem says its position.
 */
final readonly class WatchedQueue
{
    public function __construct(
        // Null when the configured name is not a connection name.
        public ?string $connection,
        // Null for an entry that stands for the whole connection, or for a name that is not a queue name.
        public ?string $queue,
        // The driver as config/queue.php gives it; null when the connection has no config entry or no driver.
        public ?string $driver,
        // Why this pair cannot be watched, or null when queue_overview may measure it.
        public ?string $problem,
    ) {}
}
