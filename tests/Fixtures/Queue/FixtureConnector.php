<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Queue;

use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\Connectors\ConnectorInterface;

/** Queue::extend() takes a connector resolver: this connector hands out one fixed queue. */
final class FixtureConnector implements ConnectorInterface
{
    public function __construct(private readonly Queue $queue) {}

    /** @param  array<string, mixed>  $config */
    public function connect(array $config)
    {
        return $this->queue;
    }
}
