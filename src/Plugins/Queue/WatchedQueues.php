<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins\Queue;

use Illuminate\Contracts\Config\Repository;

/**
 * Spec 4.5, 6.5, 01-22 Part A: the (connection, queue) pairs that queue_overview measures, from
 * runtime-mcp.queue.names. Config only: it never resolves a queue connection, so doctor uses it too.
 *
 * null watches the default connection only (Sina, 2026-10-02): a stock config/queue.php lists sqs and
 * beanstalkd even when the app does not use them. A name is shown only when it has the shape of a name
 * (Sina, 01-12): an SQS queue can be a URL that holds the AWS account ID.
 */
final class WatchedQueues
{
    /** No dot: Laravel reads queue.connections.<name> as a dot path, so a dotted name never resolves. */
    public const CONNECTION_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/D';

    /** Dots (emails.high, an SQS .fifo queue), braces (Redis Cluster {default}) and colons. A URL fails it. */
    public const QUEUE_PATTERN = '/^[A-Za-z0-9_.:{}-]{1,128}$/D';

    /** The queue of a connection whose config has no queue key (sync, deferred, null): the stock name. */
    public const DEFAULT_QUEUE = 'default';

    public const EMPTY_LIST = 'runtime-mcp.queue.names is an empty list, so no queue is watched.';

    public const BAD_SHAPE = 'runtime-mcp.queue.names must be null, a list of queue names, or a map of connection names to lists of queue names.';

    public const NO_DEFAULT = 'queue.default is not set, so there is no default queue connection.';

    public const BAD_DEFAULT = 'queue.default is not a connection name.';

    /** %s is the connection. */
    public const BAD_DEFAULT_QUEUE = 'The default queue of `%s` in config/queue.php is not a queue name.';

    /** %d is the 1-based position among the connections of the map. */
    public const BAD_CONNECTION = 'The connection at position %d of runtime-mcp.queue.names is not a connection name.';

    /** %s is the connection. */
    public const NOT_CONFIGURED = 'Connection `%s` is not in config/queue.php.';

    /** %s is the connection. */
    public const NO_DRIVER = 'Connection `%s` has no driver in config/queue.php.';

    /** %s is the connection. */
    public const BAD_QUEUES = 'The queues of `%s` in runtime-mcp.queue.names are not a list of names.';

    /** %d is the 1-based position in the list, %s the connection. */
    public const BAD_QUEUE = 'The queue at position %d of `%s` in runtime-mcp.queue.names is not a queue name.';

    public function __construct(private readonly Repository $config) {}

    /** Why no pair can be read from the config at all, or null. The tool and doctor report it as one line. */
    public function shapeProblem(): ?string
    {
        $names = $this->config->get('runtime-mcp.queue.names');

        if ($names === []) {
            return self::EMPTY_LIST;
        }

        if ($names !== null && ! is_array($names)) {
            return self::BAD_SHAPE;
        }

        if ($names === null || array_is_list($names)) {
            return $this->defaultProblem();
        }

        // A map has a connection name for every key; PHP turns '1' into 1, so a number is never a name.
        foreach (array_keys($names) as $key) {
            if (is_int($key)) {
                return self::BAD_SHAPE;
            }
        }

        return null;
    }

    /**
     * The pairs in config order, each pair once. At least one when shapeProblem() is null: a connection that
     * cannot be watched gives one entry with its problem, so nothing is left out in silence (rule 11).
     *
     * @return list<WatchedQueue> empty when shapeProblem() is not null
     */
    public function entries(): array
    {
        if ($this->shapeProblem() !== null) {
            return [];
        }

        $names = $this->config->get('runtime-mcp.queue.names');
        $default = $this->config->get('queue.default');
        $default = is_string($default) ? $default : ''; // checked by shapeProblem()

        if (! is_array($names)) {
            return $this->defaultEntry($default);
        }

        if (array_is_list($names)) {
            return self::unique($this->onConnection($default, 1, $names));
        }

        $entries = [];
        $position = 0;

        foreach ($names as $connection => $queues) {
            $position++;
            array_push($entries, ...$this->onConnection((string) $connection, $position, $queues));
        }

        return self::unique($entries);
    }

    /**
     * The name-shaped member connections of a failover connection, for its note.
     *
     * @return list<string>
     */
    public function failoverMembers(string $connection): array
    {
        $members = $this->setting($connection, 'connections');

        if (! is_array($members)) {
            return [];
        }

        return array_values(array_filter(
            $members,
            fn (mixed $member): bool => is_string($member) && preg_match(self::CONNECTION_PATTERN, $member) === 1,
        ));
    }

    private function defaultProblem(): ?string
    {
        $default = $this->config->get('queue.default');

        if (! is_string($default) || trim($default) === '') {
            return self::NO_DEFAULT;
        }

        return preg_match(self::CONNECTION_PATTERN, $default) === 1 ? null : self::BAD_DEFAULT;
    }

    /** @return list<WatchedQueue> */
    private function defaultEntry(string $default): array
    {
        $problem = $this->connectionProblem($default);

        if ($problem !== null) {
            return [$problem];
        }

        $driver = $this->driver($default);

        // The stock name only when the key is absent (sync, deferred, null). A value that is there, even '' or
        // null, is where Laravel pushes ('' ?: ''), and queue:work reads it too: default would be a false zero.
        if (! $this->hasSetting($default, 'queue')) {
            return [new WatchedQueue($default, self::DEFAULT_QUEUE, $driver, null)];
        }

        $queue = $this->setting($default, 'queue');

        if (! is_string($queue) || preg_match(self::QUEUE_PATTERN, $queue) !== 1) {
            return [new WatchedQueue($default, null, $driver, sprintf(self::BAD_DEFAULT_QUEUE, $default))];
        }

        return [new WatchedQueue($default, $queue, $driver, null)];
    }

    /** @return list<WatchedQueue> */
    private function onConnection(string $connection, int $position, mixed $queues): array
    {
        if (preg_match(self::CONNECTION_PATTERN, $connection) !== 1) {
            return [new WatchedQueue(null, null, null, sprintf(self::BAD_CONNECTION, $position))];
        }

        $problem = $this->connectionProblem($connection);

        if ($problem !== null) {
            return [$problem];
        }

        $driver = $this->driver($connection);

        if (! is_array($queues) || $queues === [] || ! array_is_list($queues)) {
            return [new WatchedQueue($connection, null, $driver, sprintf(self::BAD_QUEUES, $connection))];
        }

        $entries = [];

        foreach ($queues as $index => $queue) {
            $entries[] = is_string($queue) && preg_match(self::QUEUE_PATTERN, $queue) === 1
                ? new WatchedQueue($connection, $queue, $driver, null)
                : new WatchedQueue($connection, null, $driver, sprintf(self::BAD_QUEUE, $index + 1, $connection));
        }

        return $entries;
    }

    /** The one entry for a name-shaped connection that cannot be watched, or null when it can. */
    private function connectionProblem(string $connection): ?WatchedQueue
    {
        $all = $this->config->get('queue.connections');

        // QueueManager::getConfig(): the name null is Laravel's own null connection, with no config entry.
        if ((! is_array($all) || ! array_key_exists($connection, $all)) && $connection !== 'null') {
            return new WatchedQueue($connection, null, null, sprintf(self::NOT_CONFIGURED, $connection));
        }

        $driver = $this->driver($connection);

        return $driver === null ? new WatchedQueue($connection, null, null, sprintf(self::NO_DRIVER, $connection)) : null;
    }

    private function driver(string $connection): ?string
    {
        if ($connection === 'null') {
            return 'null';
        }

        $driver = $this->setting($connection, 'driver');

        return is_string($driver) && $driver !== '' ? $driver : null;
    }

    /** One key of a connection's config entry. The name is looked up as one key, never as a dot path. */
    private function setting(string $connection, string $key): mixed
    {
        return $this->entry($connection)[$key] ?? null;
    }

    /** Whether a connection's config entry has the key at all, with any value (null and '' too). */
    private function hasSetting(string $connection, string $key): bool
    {
        $entry = $this->entry($connection);

        return $entry !== null && array_key_exists($key, $entry);
    }

    /**
     * A connection's config entry. QueueManager::getConfig() gives the name null ['driver' => 'null'] in every
     * case, so Laravel ignores a null entry in config/queue.php, and so does this class.
     *
     * @return array<array-key, mixed>|null
     */
    private function entry(string $connection): ?array
    {
        if ($connection === 'null') {
            return null;
        }

        $all = $this->config->get('queue.connections');
        $entry = is_array($all) ? ($all[$connection] ?? null) : null;

        return is_array($entry) ? $entry : null;
    }

    /**
     * Each measurable pair once, at its first position. An entry with a problem is kept as it is.
     *
     * @param  list<WatchedQueue>  $entries
     * @return list<WatchedQueue>
     */
    private static function unique(array $entries): array
    {
        $seen = [];
        $kept = [];

        foreach ($entries as $entry) {
            if ($entry->problem === null) {
                $key = $entry->connection."\0".$entry->queue;

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
            }

            $kept[] = $entry;
        }

        return $kept;
    }
}
