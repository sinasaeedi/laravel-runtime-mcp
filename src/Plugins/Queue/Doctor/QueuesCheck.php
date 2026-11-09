<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins\Queue\Doctor;

use SinaSaeedi\RuntimeMcp\Doctor\Check;
use SinaSaeedi\RuntimeMcp\Doctor\CheckResult;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\WatchedQueues;

/**
 * Spec 4.5, 7.3, 01-22 Part A: runtime-mcp.queue.names. Config only, through the same WatchedQueues as the
 * tool, so doctor never contacts a queue backend either. Every name it prints has the shape of a name (01-12).
 */
final class QueuesCheck implements Check
{
    public const FIX = 'Fix runtime-mcp.queue.names, or add the connection to config/queue.php.';

    /** %s is the connection. */
    public const SQS = 'Connection `%s` is watched, but queue_overview does not read SQS in v1.0.0.';

    public const SQS_FIX = 'Watch another connection, or read the size of the SQS queue in the AWS console.';

    /** %d is the number of watched queues, %s the connections. */
    public const WATCHING = 'queue_overview watches %d queue(s) on: %s.';

    public function __construct(private readonly WatchedQueues $watched) {}

    public function title(): string
    {
        return 'Queues';
    }

    public function run(): array
    {
        $problem = $this->watched->shapeProblem();

        if ($problem !== null) {
            return [CheckResult::warn($problem, self::FIX)];
        }

        $entries = $this->watched->entries();
        $results = [];
        $connections = [];

        foreach ($entries as $entry) {
            if ($entry->problem !== null) {
                $results[] = CheckResult::warn($entry->problem, self::FIX);

                continue;
            }

            $connection = (string) $entry->connection;

            if ($entry->driver === 'sqs' && ! isset($connections[$connection])) {
                $results[] = CheckResult::warn(sprintf(self::SQS, $connection), self::SQS_FIX);
            }

            $connections[$connection] = true;
        }

        if ($results !== []) {
            return $results;
        }

        $names = array_map(fn (string $name): string => "`{$name}`", array_keys($connections));

        return [CheckResult::pass(sprintf(self::WATCHING, count($entries), implode(', ', $names)))];
    }
}
