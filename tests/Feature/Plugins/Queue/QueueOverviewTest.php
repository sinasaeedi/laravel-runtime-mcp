<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Database\QueryException;
use Illuminate\Queue\BackgroundQueue;
use Illuminate\Queue\DeferredQueue;
use Illuminate\Queue\FailoverQueue;
use Illuminate\Queue\NullQueue;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue as QueueFacade;
use SinaSaeedi\RuntimeMcp\Mcp\EnvelopeSchema;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\QueuePlugin;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\Tools\QueueFailedJobShow;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\Tools\QueueFailedJobsList;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\Tools\QueueFailedJobsSummary;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\Tools\QueueOverview;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\WatchedQueues;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Queue\CountingQueue;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Queue\FixtureConnector;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Queue\ImmediateQueue;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Queue\PlainSqsQueue;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Queue\TextSizeQueue;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Queue\ThrowingQueue;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\FixtureTools;
use SinaSaeedi\RuntimeMcp\Tests\Support\Gates;
use SinaSaeedi\RuntimeMcp\Tests\Support\JsonSchemaSubset;
use SinaSaeedi\RuntimeMcp\Tests\Support\ProtocolEra;
use SinaSaeedi\RuntimeMcp\Tests\Support\QueueJobs;

/*
 * 01-22 (spec 6.5, rules 4 and 11): queue_overview over HTTP with a valid key and a Gate, on SQLite. Testbench's
 * config/queue.php is Laravel's stock one: sync (the default), database, beanstalkd, sqs, redis, deferred and
 * failover. No RefreshDatabase: a test transaction on the default connection makes every read tool fail (01-16).
 */

beforeEach(fn () => AuditLog::clear());
afterEach(fn () => AuditLog::clear());

/**
 * Boots the package and allows queue_overview. The rate limiter keeps the array store (as app_info's test).
 *
 * @param  array<string, mixed>  $config
 */
function queueOverviewServe(array $config = []): void
{
    currentTest()->bootWith(['runtime-mcp.enabled' => true, 'cache.limiter' => 'array', ...$config]);
    Gates::allow('queue_overview');
}

/** @return array<array-key, mixed> the envelope of one call */
function queueOverviewEnvelope(ProtocolEra $era = ProtocolEra::Legacy): array
{
    $envelope = FixtureTools::envelope(FixtureTools::call('queue_overview', era: $era));
    expect(JsonSchemaSubset::violations($envelope, EnvelopeSchema::toArray()))->toBe([]);

    return $envelope;
}

/**
 * @param  array<array-key, mixed>  $envelope
 * @return list<mixed> the queues of an ok or partial envelope
 */
function queueOverviewQueues(array $envelope): array
{
    $queues = data_get($envelope, 'data.queues');
    expect($queues)->toBeArray();
    assert(is_array($queues));

    return array_values($queues);
}

/** @return list<string> the queue connections that something resolved, of every configured one and null */
function queueOverviewResolved(): array
{
    $manager = app(QueueManager::class);
    $names = array_keys((array) config('queue.connections'));

    return array_values(array_filter([...$names, 'null'], fn (int|string $name): bool => $manager->connected((string) $name)));
}

/** A custom driver (Queue::extend()) whose connector hands out $queue. */
function queueOverviewExtend(string $driver, Queue $queue): void
{
    app(QueueManager::class)->extend($driver, fn (): FixtureConnector => new FixtureConnector($queue));
}

/**
 * One envelope row.
 *
 * @return array<string, mixed>
 */
function queueOverviewRow(?string $connection, ?string $driver, ?string $queue, ?int $size, ?string $note = null): array
{
    return [
        'connection' => $connection, 'driver' => $driver, 'queue' => $queue, 'size' => $size,
        'status' => $note === null ? 'ok' : 'unavailable', 'note' => $note,
    ];
}

it('QO1 counts the jobs of each listed queue on a database connection, in both protocol eras', function (ProtocolEra $era) {
    queueOverviewServe(['queue.default' => 'database', 'runtime-mcp.queue.names' => ['default', 'emails', 'reports']]);
    QueueJobs::create(jobs: ['default' => 3, 'emails' => 2, 'other' => 4]);

    $envelope = queueOverviewEnvelope($era);

    expect($envelope['status'])->toBe('ok')
        ->and($envelope['note'])->toBeNull()
        ->and($envelope['truncated'])->toBeFalse()
        ->and($envelope['data'])->toBe(['queues' => [
            queueOverviewRow('database', 'database', 'default', 3),
            queueOverviewRow('database', 'database', 'emails', 2),
            queueOverviewRow('database', 'database', 'reports', 0),
        ]]);
})->with([ProtocolEra::Legacy, ProtocolEra::Modern]);

it('QO2 watches only the default connection and its default queue when names is null', function () {
    queueOverviewServe(['queue.default' => 'database']);
    QueueJobs::create(jobs: ['default' => 3, 'emails' => 2]);

    expect(queueOverviewEnvelope()['data'])->toBe(['queues' => [queueOverviewRow('database', 'database', 'default', 3)]])
        // The stock sqs, beanstalkd and redis connections are never resolved (rule 4).
        ->and(queueOverviewResolved())->toBe(['database']);
});

/* The connections with no queue to measure: the config, then the note. */
const QUEUE_OVERVIEW_NO_QUEUE = [
    'sync (the stock default)' => [[], 'Connection `sync` runs jobs immediately, so it has no queue to measure.'],
    'deferred' => [['queue.default' => 'deferred'], 'Connection `deferred` runs jobs after the response is sent, so it has no queue to measure.'],
    'background' => [
        ['queue.default' => 'later', 'queue.connections.later' => ['driver' => 'background']],
        'Connection `later` runs jobs in a background process, so it has no queue to measure.',
    ],
    'null, with no config entry' => [['queue.default' => 'null'], 'Connection `null` discards jobs, so it has no queue to measure.'],
    'failover (stock: database, deferred)' => [
        ['queue.default' => 'failover'],
        'Connection `failover` sends jobs to other connections (database, deferred). Watch those connections by name.',
    ],
    'failover with no member name to show' => [
        ['queue.default' => 'failover', 'queue.connections.failover.connections' => ['has space']],
        'Connection `failover` sends jobs to other connections. Watch those connections by name.',
    ],
    'sqs, named in the map' => [
        ['runtime-mcp.queue.names' => ['sqs' => ['default']]],
        'Connection `sqs` uses Amazon SQS. Reading its size would call AWS; v1.0.0 does not call AWS.',
    ],
];

it('QO3 lists a connection with no queue to measure as unavailable, never as zero, and resolves nothing', function (string $case) {
    [$config, $note] = QUEUE_OVERVIEW_NO_QUEUE[$case];
    queueOverviewServe($config);

    $envelope = queueOverviewEnvelope();

    expect($envelope['status'])->toBe('unavailable')
        ->and($envelope['note'])->toBe($note)
        ->and($envelope['data'])->toBeNull()
        ->and(queueOverviewResolved())->toBe([]);
})->with(array_keys(QUEUE_OVERVIEW_NO_QUEUE));

it('QO4 puts every distinct reason in the note when no watched queue could be measured', function () {
    queueOverviewServe(['runtime-mcp.queue.names' => ['sync' => ['default'], 'deferred' => ['a', 'b']]]);

    $envelope = queueOverviewEnvelope();

    expect($envelope['status'])->toBe('unavailable')
        ->and($envelope['note'])->toBe('None of the 3 watched queues could be measured. '
            .'Connection `sync` runs jobs immediately, so it has no queue to measure. '
            .'Connection `deferred` runs jobs after the response is sent, so it has no queue to measure.')
        ->and($envelope['data'])->toBeNull();
});

it('QO5 is partial when one backend fails: the others keep their sizes, the agent gets the class only', function () {
    queueOverviewServe(['queue.default' => 'database', 'runtime-mcp.queue.names' => [
        'database' => ['default'], 'broken' => ['default'],
    ], 'queue.connections.broken' => ['driver' => 'broken']]);
    QueueJobs::create(jobs: ['default' => 2]);
    queueOverviewExtend('broken', new ThrowingQueue);
    Exceptions::fake();

    $response = FixtureTools::call('queue_overview');
    $envelope = FixtureTools::envelope($response);

    expect($envelope['status'])->toBe('partial')
        ->and($envelope['note'])->toBe('1 of 2 queues could not be measured; see each entry.')
        ->and($envelope['truncated'])->toBeFalse()
        ->and(queueOverviewQueues($envelope))->toBe([
            queueOverviewRow('database', 'database', 'default', 2),
            queueOverviewRow('broken', 'broken', 'default', null, 'Could not read the size of `default` on `broken`: RuntimeException.'),
        ])
        ->and(str_contains((string) $response->getContent(), 'queue.internal.example'))->toBeFalse();

    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === ThrowingQueue::MESSAGE);
    Exceptions::assertReportedCount(1);
});

it('QO6 gives a custom driver the note of the queue class it is built on, and never calls its size()', function (string $class, string $note) {
    queueOverviewServe(['queue.default' => 'custom', 'queue.connections.custom' => ['driver' => 'custom-driver']]);
    queueOverviewExtend('custom-driver', match ($class) {
        'SyncQueue' => new ImmediateQueue,
        'DeferredQueue' => new DeferredQueue,
        'BackgroundQueue' => new BackgroundQueue,
        'NullQueue' => new NullQueue,
        'FailoverQueue' => new FailoverQueue(app(QueueManager::class), app(Dispatcher::class), ['database']),
        default => new PlainSqsQueue,
    });

    $envelope = queueOverviewEnvelope();

    expect($envelope['status'])->toBe('unavailable')
        ->and($envelope['note'])->toBe($note);
})->with([
    ['SyncQueue', 'Connection `custom` runs jobs immediately, so it has no queue to measure.'],
    ['DeferredQueue', 'Connection `custom` runs jobs after the response is sent, so it has no queue to measure.'],
    ['BackgroundQueue', 'Connection `custom` runs jobs in a background process, so it has no queue to measure.'],
    ['NullQueue', 'Connection `custom` discards jobs, so it has no queue to measure.'],
    ['FailoverQueue', 'Connection `custom` sends jobs to other connections. Watch those connections by name.'],
    ['SqsQueue', 'Connection `custom` uses Amazon SQS. Reading its size would call AWS; v1.0.0 does not call AWS.'],
]);

it('QO7 refuses a size that is not a count of 0 or more', function (mixed $size) {
    queueOverviewServe(['queue.default' => 'custom', 'queue.connections.custom' => ['driver' => 'custom', 'queue' => 'jobs']]);
    queueOverviewExtend('custom', new TextSizeQueue($size));

    expect(queueOverviewEnvelope()['note'])->toBe('Could not read the size of `jobs` on `custom`: the driver returned no count.');
})->with([
    'a string' => ['3'],
    'a negative number' => [-1],
    'null' => [null],
    'a float' => [2.0],
]);

it('QO8 asks each driver for the watched queue names, and shows a driver name only when it has a name\'s shape', function () {
    $queue = new CountingQueue(['{high}' => 7, 'emails.high' => 1]);
    queueOverviewServe(['runtime-mcp.queue.names' => ['counted' => ['{high}', 'emails.high', '{high}']],
        'queue.connections.counted' => ['driver' => 'App\Queue\Fancy']]);
    queueOverviewExtend('App\Queue\Fancy', $queue);

    expect(queueOverviewEnvelope()['data'])->toBe(['queues' => [
        queueOverviewRow('counted', 'unknown', '{high}', 7),
        queueOverviewRow('counted', 'unknown', 'emails.high', 1),
    ]])
        ->and($queue->asked)->toBe(['{high}', 'emails.high']);
});

it('QO9 is unavailable with the class when the database driver has no jobs table (spec 11.2 "Envelope")', function () {
    queueOverviewServe(['queue.default' => 'database']);
    Exceptions::fake();

    $envelope = queueOverviewEnvelope();

    expect($envelope['status'])->toBe('unavailable')
        ->and($envelope['note'])->toBe('Could not read the size of `default` on `database`: '.QueryException::class.'.');

    Exceptions::assertReported(QueryException::class);
});

/* A names value it cannot read: the value, more config, then the note. */
const QUEUE_OVERVIEW_BAD_NAMES = [
    'an empty list' => [[], [], WatchedQueues::EMPTY_LIST],
    'a string' => ['default', [], WatchedQueues::BAD_SHAPE],
    'queue.default empty' => [null, ['queue.default' => ''], WatchedQueues::NO_DEFAULT],
];

it('QO10 is unavailable with one note for a names value it cannot read', function (string $case) {
    [$names, $config, $note] = QUEUE_OVERVIEW_BAD_NAMES[$case];
    queueOverviewServe(['runtime-mcp.queue.names' => $names, ...$config]);

    $envelope = queueOverviewEnvelope();

    expect($envelope['status'])->toBe('unavailable')
        ->and($envelope['note'])->toBe($note)
        ->and($envelope['data'])->toBeNull()
        ->and(queueOverviewResolved())->toBe([]);
})->with(array_keys(QUEUE_OVERVIEW_BAD_NAMES));

it('QO11 reports each entry it cannot watch, and never shows a name that is not name-shaped', function () {
    queueOverviewServe(['runtime-mcp.queue.names' => [
        'beanstalk' => ['default'],
        'has space' => ['default'],
        'database' => 'default',
        'redis' => ['https://sqs.us-east-1.amazonaws.com/123456789012/orders'],
    ]]);

    $response = FixtureTools::call('queue_overview');
    $envelope = FixtureTools::envelope($response);

    expect($envelope['status'])->toBe('unavailable')
        ->and($envelope['note'])->toBe('None of the 4 watched queues could be measured. '
            .'Connection `beanstalk` is not in config/queue.php. '
            .'The connection at position 2 of runtime-mcp.queue.names is not a connection name. '
            .'The queues of `database` in runtime-mcp.queue.names are not a list of names. '
            .'The queue at position 1 of `redis` in runtime-mcp.queue.names is not a queue name.')
        ->and(str_contains((string) $response->getContent(), '123456789012'))->toBeFalse()
        ->and(str_contains((string) $response->getContent(), 'has space'))->toBeFalse()
        ->and(queueOverviewResolved())->toBe([]);
});

it('QO12 audits the number of entries as the row count', function () {
    queueOverviewServe(['queue.default' => 'database', 'runtime-mcp.queue.names' => ['default', 'emails', 'reports']]);
    QueueJobs::create(jobs: ['default' => 5]);

    queueOverviewEnvelope();
    $entries = AuditLog::entries();

    expect($entries)->toHaveCount(1)
        ->and($entries[0])->toMatchArray(['tool' => 'queue_overview', 'outcome' => 'success', 'row_count' => 3]);
});

it('QO13 keeps the tool contract, is registered by the queue plugin, and is listed with a Gate', function () {
    queueOverviewServe(['runtime-mcp.queue.names' => 5]);

    assertToolContract(app(QueueOverview::class), app(QueuePlugin::class));

    // Built for tools/list on every request: a names value it cannot read does not break the list.
    expect(app(QueuePlugin::class)->tools())->toBe([QueueOverview::class, QueueFailedJobsList::class, QueueFailedJobsSummary::class, QueueFailedJobShow::class])
        ->and(currentTest()->mcpPost('tools/list')->assertOk()->json('result.tools.*.name'))->toContain('queue_overview')
        ->and(app(QueueOverview::class)->description())->toContain('never as zero');
});

it('QO14 is unavailable, never a zero for default, when the default queue in config/queue.php is empty', function () {
    queueOverviewServe(['queue.default' => 'database', 'queue.connections.database.queue' => '']);
    QueueJobs::create();
    // Laravel pushes to the queue '' ('' ?: ''), and queue:work reads '' too: the jobs wait there.
    QueueFacade::connection('database')->pushRaw('{}');

    $envelope = queueOverviewEnvelope();

    expect(DB::table('jobs')->pluck('queue')->all())->toBe([''])
        ->and($envelope['status'])->toBe('unavailable')
        ->and($envelope['note'])->toBe('The default queue of `database` in config/queue.php is not a queue name.')
        ->and($envelope['data'])->toBeNull();
});

it('QO15 gives the name null the null note, as Laravel does, even when config/queue.php defines it', function () {
    queueOverviewServe(['queue.default' => 'null', 'queue.connections.null' => ['driver' => 'sqs', 'queue' => 'kept']]);

    $envelope = queueOverviewEnvelope();

    expect($envelope['note'])->toBe('Connection `null` discards jobs, so it has no queue to measure.')
        ->and(queueOverviewResolved())->toBe([]);
});
