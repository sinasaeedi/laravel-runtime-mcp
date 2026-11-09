<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\WatchedQueue;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\WatchedQueues;

/*
 * 01-22 Part A (spec 4.5): the three shapes of runtime-mcp.queue.names, and every other value. Config only,
 * so a plain Repository is enough. The connections below stand for a stock config/queue.php.
 */

const WATCHED_CONNECTIONS = [
    'redis' => ['driver' => 'redis', 'connection' => 'default', 'queue' => 'jobs'],
    'database' => ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default'],
    'sync' => ['driver' => 'sync'],
    'blank' => ['driver' => 'database', 'queue' => ''],
    'sqs' => ['driver' => 'sqs', 'queue' => 'https://sqs.us-east-1.amazonaws.com/123456789012/orders'],
    'failover' => ['driver' => 'failover', 'connections' => ['database', 'has space', 'sync', 7]],
    'nodriver' => ['queue' => 'default'],
    'emptydriver' => ['driver' => '', 'queue' => 'default'],
    'notarray' => 'redis',
];

/** @param  array<string, mixed>  $queue  the queue config; the defaults are WATCHED_CONNECTIONS on redis */
function watchedQueues(mixed $names, array $queue = []): WatchedQueues
{
    return new WatchedQueues(new Repository([
        'runtime-mcp' => ['queue' => ['names' => $names]],
        'queue' => ['default' => 'redis', 'connections' => WATCHED_CONNECTIONS, ...$queue],
    ]));
}

/** @return list<array{string|null, string|null, string|null, string|null}> */
function watchedEntries(WatchedQueues $watched): array
{
    return array_map(
        fn (WatchedQueue $entry): array => [$entry->connection, $entry->queue, $entry->driver, $entry->problem],
        $watched->entries(),
    );
}

it('QW1 watches the default queue of the default connection when names is null', function () {
    $watched = watchedQueues(null);

    expect($watched->shapeProblem())->toBeNull()
        ->and(watchedEntries($watched))->toBe([['redis', 'jobs', 'redis', null]]);
});

it('QW2 takes the queue named default when the connection has no queue key', function () {
    expect(watchedEntries(watchedQueues(null, ['default' => 'sync'])))->toBe([['sync', 'default', 'sync', null]]);
});

it('QW3 refuses a default queue that is not a queue name, and never shows it', function () {
    expect(watchedEntries(watchedQueues(null, ['default' => 'sqs'])))->toBe([
        ['sqs', null, 'sqs', 'The default queue of `sqs` in config/queue.php is not a queue name.'],
    ]);
});

it('QW4 reports a default connection that is not configured or has no driver', function (string $default, string $note) {
    expect(watchedEntries(watchedQueues(null, ['default' => $default])))->toBe([[$default, null, null, $note]]);
})->with([
    'not in config/queue.php' => ['beanstalk', 'Connection `beanstalk` is not in config/queue.php.'],
    'no driver key' => ['nodriver', 'Connection `nodriver` has no driver in config/queue.php.'],
    'an empty driver' => ['emptydriver', 'Connection `emptydriver` has no driver in config/queue.php.'],
    'an entry that is not an array' => ['notarray', 'Connection `notarray` has no driver in config/queue.php.'],
]);

it('QW5 treats the name null as Laravel\'s own null connection, even when config/queue.php defines it', function () {
    expect(watchedEntries(watchedQueues(null, ['default' => 'null'])))->toBe([['null', 'default', 'null', null]])
        ->and(watchedEntries(watchedQueues(['a'], ['default' => 'null'])))->toBe([['null', 'a', 'null', null]])
        ->and(watchedEntries(watchedQueues(null, [
            'default' => 'null',
            'connections' => ['null' => ['driver' => 'redis', 'queue' => 'kept']],
        ])))->toBe([['null', 'default', 'null', null]])
        // QueueManager::getConfig() ignores the entry: no driver is not a problem, and sqs is not read.
        ->and(watchedEntries(watchedQueues(['a'], [
            'default' => 'null',
            'connections' => ['null' => ['queue' => 'x']],
        ])))->toBe([['null', 'a', 'null', null]])
        ->and(watchedEntries(watchedQueues(['null' => ['a']], [
            'connections' => ['null' => ['driver' => 'sqs']],
        ])))->toBe([['null', 'a', 'null', null]]);
});

/* A shape it cannot read: the names value, the queue config, then the problem. */
const WATCHED_BAD_SHAPES = [
    'an empty list' => [[], [], WatchedQueues::EMPTY_LIST],
    'a string' => ['default', [], WatchedQueues::BAD_SHAPE],
    'a number' => [5, [], WatchedQueues::BAD_SHAPE],
    'false' => [false, [], WatchedQueues::BAD_SHAPE],
    'a map with a number key' => [[1 => ['a'], 'redis' => ['b']], [], WatchedQueues::BAD_SHAPE],
    'a map key PHP made a number' => [['1' => ['a']], [], WatchedQueues::BAD_SHAPE],
    'null, no queue.default' => [null, ['default' => null], WatchedQueues::NO_DEFAULT],
    'null, queue.default only spaces' => [null, ['default' => '  '], WatchedQueues::NO_DEFAULT],
    'a list, queue.default not a string' => [['a'], ['default' => ['redis']], WatchedQueues::NO_DEFAULT],
    'null, a dotted queue.default' => [null, ['default' => 'queue.redis'], WatchedQueues::BAD_DEFAULT],
    'a list, a queue.default with a space' => [['a'], ['default' => 're dis'], WatchedQueues::BAD_DEFAULT],
];

it('QW6 has one problem for a shape it cannot read, and then no entries', function (string $case) {
    [$names, $queue, $problem] = WATCHED_BAD_SHAPES[$case];
    $watched = watchedQueues($names, $queue);

    expect($watched->shapeProblem())->toBe($problem)
        ->and($watched->entries())->toBe([]);
})->with(array_keys(WATCHED_BAD_SHAPES));

it('QW7 watches a list on the default connection, once each, and names a bad item by its position', function () {
    $watched = watchedQueues(['default', 'emails', 'emails', 'https://example.test/q', 7, '{high}', 'emails.high:1']);

    expect($watched->shapeProblem())->toBeNull()
        ->and(watchedEntries($watched))->toBe([
            ['redis', 'default', 'redis', null],
            ['redis', 'emails', 'redis', null],
            ['redis', null, 'redis', 'The queue at position 4 of `redis` in runtime-mcp.queue.names is not a queue name.'],
            ['redis', null, 'redis', 'The queue at position 5 of `redis` in runtime-mcp.queue.names is not a queue name.'],
            ['redis', '{high}', 'redis', null],
            ['redis', 'emails.high:1', 'redis', null],
        ]);
});

it('QW8 watches a map in config order, with one entry for each connection it cannot watch', function () {
    $watched = watchedQueues([
        'redis' => ['a', 'b', 'a'],
        'database' => ['a'],
        'beanstalk' => ['x'],
        'has space' => ['x'],
        'queue.redis' => ['x'],
        'sync' => 'default',
        'sqs' => [],
        'failover' => ['high' => 'a'],
        'nodriver' => ['q'],
        'null' => ['default', ''],
    ]);

    expect($watched->shapeProblem())->toBeNull()
        ->and(watchedEntries($watched))->toBe([
            ['redis', 'a', 'redis', null],
            ['redis', 'b', 'redis', null],
            ['database', 'a', 'database', null],
            ['beanstalk', null, null, 'Connection `beanstalk` is not in config/queue.php.'],
            [null, null, null, 'The connection at position 4 of runtime-mcp.queue.names is not a connection name.'],
            [null, null, null, 'The connection at position 5 of runtime-mcp.queue.names is not a connection name.'],
            ['sync', null, 'sync', 'The queues of `sync` in runtime-mcp.queue.names are not a list of names.'],
            ['sqs', null, 'sqs', 'The queues of `sqs` in runtime-mcp.queue.names are not a list of names.'],
            ['failover', null, 'failover', 'The queues of `failover` in runtime-mcp.queue.names are not a list of names.'],
            ['nodriver', null, null, 'Connection `nodriver` has no driver in config/queue.php.'],
            ['null', 'default', 'null', null],
            ['null', null, 'null', 'The queue at position 2 of `null` in runtime-mcp.queue.names is not a queue name.'],
        ]);
});

it('QW9 keeps the same queue on two connections, and needs no queue.connections to report a map entry', function () {
    expect(watchedEntries(watchedQueues(['redis' => ['a'], 'database' => ['a']])))->toBe([
        ['redis', 'a', 'redis', null],
        ['database', 'a', 'database', null],
    ])
        ->and(watchedEntries(watchedQueues(['redis' => ['a']], ['connections' => null])))
        ->toBe([['redis', null, null, 'Connection `redis` is not in config/queue.php.']]);
});

it('QW10 lists the name-shaped members of a failover connection', function () {
    expect(watchedQueues(null)->failoverMembers('failover'))->toBe(['database', 'sync'])
        ->and(watchedQueues(null)->failoverMembers('redis'))->toBe([])
        ->and(watchedQueues(null)->failoverMembers('beanstalk'))->toBe([])
        ->and(watchedQueues(null, ['connections' => ['failover' => ['driver' => 'failover', 'connections' => 'database']]])
            ->failoverMembers('failover'))->toBe([]);
});

it('QW11 accepts the longest names and refuses one character more', function () {
    $connection = str_repeat('c', 64);
    $queue = str_repeat('q', 128);
    $config = ['connections' => [$connection => ['driver' => 'redis'], "{$connection}c" => ['driver' => 'redis']]];

    expect(watchedEntries(watchedQueues([$connection => [$queue, "{$queue}q"], "{$connection}c" => ['a']], $config)))->toBe([
        [$connection, $queue, 'redis', null],
        [$connection, null, 'redis', "The queue at position 2 of `{$connection}` in runtime-mcp.queue.names is not a queue name."],
        [null, null, null, 'The connection at position 2 of runtime-mcp.queue.names is not a connection name.'],
    ]);
});

/* A default queue key that is there but is not a queue name: Laravel pushes to it ('' ?: ''), so default would be a false zero. */
const WATCHED_PRESENT_DEFAULT_QUEUES = ['an empty string' => '', 'null' => null, 'a number' => 5];

it('QW12 refuses a default queue key that is there but empty or not a string, and never measures default instead', function (string $case) {
    $connections = ['x' => ['driver' => 'redis', 'queue' => WATCHED_PRESENT_DEFAULT_QUEUES[$case]]];

    expect(watchedEntries(watchedQueues(null, ['default' => 'x', 'connections' => $connections])))->toBe([
        ['x', null, 'redis', 'The default queue of `x` in config/queue.php is not a queue name.'],
    ]);
})->with(array_keys(WATCHED_PRESENT_DEFAULT_QUEUES));
