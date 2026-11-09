<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins\Queue\Tools;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\BackgroundQueue;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Queue\DeferredQueue;
use Illuminate\Queue\FailoverQueue;
use Illuminate\Queue\NullQueue;
use Illuminate\Queue\SqsQueue;
use Illuminate\Queue\SyncQueue;
use SinaSaeedi\RuntimeMcp\Plugins\Core\Tools\AppInfo;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\WatchedQueue;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\WatchedQueues;
use SinaSaeedi\RuntimeMcp\Tools\Contracts\RuntimeTool;
use SinaSaeedi\RuntimeMcp\Tools\Tier;
use SinaSaeedi\RuntimeMcp\Tools\ToolInput;
use SinaSaeedi\RuntimeMcp\Tools\ToolResult;
use Throwable;

/**
 * Spec 6.5, 01-22 Part B: the size of each watched queue, or why it has none. Never a false zero (rule 11):
 * SyncQueue::size() and NullQueue::size() return 0, and DeferredQueue and BackgroundQueue extend SyncQueue.
 *
 * Only the watched connections are resolved (rule 4), and never one whose driver has no queue or is sqs (an
 * AWS call; Sina, 2026-11-06). A database queue counts on the app's own connection, inside 01-16's read-only
 * transaction, with no statement timeout: jobs is a denied table, so the read-only role cannot read it
 * (Sina, 2026-11-06).
 *
 * Built through the container on every request (also tools/list), so the constructor reads nothing.
 */
final class QueueOverview extends RuntimeTool
{
    /** By driver: the drivers with no queue to measure, and sqs. %s is the operator's connection name. */
    public const NO_QUEUE = [
        'sync' => 'Connection `%s` runs jobs immediately, so it has no queue to measure.',
        'deferred' => 'Connection `%s` runs jobs after the response is sent, so it has no queue to measure.',
        'background' => 'Connection `%s` runs jobs in a background process, so it has no queue to measure.',
        'null' => 'Connection `%s` discards jobs, so it has no queue to measure.',
        'sqs' => 'Connection `%s` uses Amazon SQS. Reading its size would call AWS; v1.0.0 does not call AWS.',
    ];

    /** FailoverQueue::size() measures only its first member. %s: the connection, then its members. */
    public const FAILOVER = 'Connection `%s` sends jobs to other connections (%s). Watch those connections by name.';

    public const FAILOVER_UNNAMED = 'Connection `%s` sends jobs to other connections. Watch those connections by name.';

    /** %s: the queue, the connection, the exception class. Never the message: it can hold a host. */
    public const FAILED = 'Could not read the size of `%s` on `%s`: %s.';

    public const NO_COUNT = 'Could not read the size of `%s` on `%s`: the driver returned no count.';

    public const PARTIAL = '%d of %d queues could not be measured; see each entry.';

    public const NONE = 'None of the %d watched queues could be measured.';

    public function __construct(
        private readonly WatchedQueues $watched,
        private readonly QueueFactory $queues,
        private readonly ExceptionHandler $exceptions,
    ) {}

    public function name(): string
    {
        return 'queue_overview';
    }

    public function description(): string
    {
        return 'Shows how many jobs wait in each watched queue (runtime-mcp.queue.names), per queue connection. '
            .'A connection with no queue to measure (sync, deferred, background, null, failover) or one this '
            .'version does not read (sqs) is listed as unavailable with the reason, never as zero. Takes no input.';
    }

    public function tier(): Tier
    {
        return Tier::Read;
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function rules(): array
    {
        return [];
    }

    public function execute(ToolInput $input): ToolResult
    {
        $problem = $this->watched->shapeProblem();

        if ($problem !== null) {
            return ToolResult::unavailable($problem);
        }

        $entries = array_map($this->measure(...), $this->watched->entries());
        $count = count($entries);
        $notes = [];

        foreach ($entries as $entry) {
            if ($entry['note'] !== null) {
                $notes[$entry['note']] = true;
            }
        }

        $unmeasured = count(array_filter($entries, fn (array $entry): bool => $entry['status'] !== 'ok'));
        $data = ['queues' => $entries];

        if ($unmeasured === 0) {
            return ToolResult::ok($data, $count)->withListKey('queues');
        }

        if ($unmeasured < $count) {
            return ToolResult::partial($data, sprintf(self::PARTIAL, $unmeasured, $count), false, $count)->withListKey('queues');
        }

        // unavailable() holds no data (01-08), so the note carries every distinct reason, in order.
        $notes = array_keys($notes);

        return ToolResult::unavailable($count === 1 ? $notes[0] : sprintf(self::NONE, $count).' '.implode(' ', $notes));
    }

    /** @return array{connection: string|null, driver: string|null, queue: string|null, size: int|null, status: string, note: string|null} */
    private function measure(WatchedQueue $entry): array
    {
        $connection = (string) $entry->connection;
        $queue = (string) $entry->queue;
        $size = null;
        $note = $entry->problem ?? $this->noQueue($connection, $entry->driver);

        if ($note === null) {
            [$size, $note] = $this->size($connection, $queue);
        }

        return [
            'connection' => $entry->connection,
            'driver' => self::driverName($entry->driver),
            'queue' => $entry->queue,
            'size' => $size,
            'status' => $note === null ? 'ok' : 'unavailable',
            'note' => $note,
        ];
    }

    /** The note of a driver with no queue to measure, or null. Config only: nothing is resolved. */
    private function noQueue(string $connection, ?string $driver): ?string
    {
        if ($driver === 'failover') {
            $members = $this->watched->failoverMembers($connection);

            return $members === []
                ? sprintf(self::FAILOVER_UNNAMED, $connection)
                : sprintf(self::FAILOVER, $connection, implode(', ', $members));
        }

        return $driver !== null && isset(self::NO_QUEUE[$driver]) ? sprintf(self::NO_QUEUE[$driver], $connection) : null;
    }

    /**
     * Resolves the connection and counts. A custom driver whose class extends a queue with no size gets that
     * queue's note, so a subclass of SyncQueue cannot bring the false zero back.
     *
     * @return array{int|null, string|null} the size, or null and the note
     */
    private function size(string $connection, string $name): array
    {
        try {
            $queue = $this->queues->connection($connection);
            $note = $this->noQueue($connection, self::classDriver($queue));

            if ($note !== null) {
                return [null, $note];
            }

            // A savepoint: on PostgreSQL one failed count aborts 01-16's read-only transaction, and every later
            // entry on the same connection would fail too. ROLLBACK TO SAVEPOINT keeps it usable.
            $size = $queue instanceof DatabaseQueue
                ? $queue->getDatabase()->transaction(fn (): mixed => $queue->size($name))
                : $queue->size($name);
        } catch (Throwable $e) {
            $this->exceptions->report($e);

            return [null, sprintf(self::FAILED, $name, $connection, $e::class)];
        }

        $count = self::count($size);

        return $count === null ? [null, sprintf(self::NO_COUNT, $name, $connection)] : [$count, null];
    }

    /** A custom driver's size() may break its contract: a count is an int of 0 or more. */
    private static function count(mixed $size): ?int
    {
        return is_int($size) && $size >= 0 ? $size : null;
    }

    /** The driver whose note a resolved queue gets by its class, or null when it can be measured. */
    private static function classDriver(Queue $queue): ?string
    {
        return match (true) {
            // Before SyncQueue: both extend it.
            $queue instanceof DeferredQueue => 'deferred',
            $queue instanceof BackgroundQueue => 'background',
            $queue instanceof SyncQueue => 'sync',
            $queue instanceof NullQueue => 'null',
            $queue instanceof FailoverQueue => 'failover',
            // A package driver built on SqsQueue (another driver name) would still call AWS.
            $queue instanceof SqsQueue => 'sqs',
            default => null,
        };
    }

    /** The driver as app_info shows one (01-18): a name, or unknown; null when the connection has no driver. */
    private static function driverName(?string $driver): ?string
    {
        if ($driver === null) {
            return null;
        }

        return preg_match(AppInfo::DRIVER_PATTERN, $driver) === 1 ? $driver : AppInfo::UNKNOWN;
    }
}
