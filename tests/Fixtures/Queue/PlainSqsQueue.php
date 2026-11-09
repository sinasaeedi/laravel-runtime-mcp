<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Queue;

use Illuminate\Queue\SqsQueue;
use LogicException;

/**
 * A package driver built on SqsQueue under another driver name (as plain-SQS packages do). No SqsClient: the
 * AWS SDK is not installed here. A size() call stands for the AWS call that queue_overview must never make.
 */
final class PlainSqsQueue extends SqsQueue
{
    public const CALLED = 'queue_overview called size() on an SQS queue.';

    public function __construct() {}

    public function size($queue = null)
    {
        throw new LogicException(self::CALLED);
    }
}
