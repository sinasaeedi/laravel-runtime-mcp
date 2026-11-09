<?php

declare(strict_types=1);

use Illuminate\Queue\QueueManager;
use SinaSaeedi\RuntimeMcp\Doctor\CheckResult;
use SinaSaeedi\RuntimeMcp\Doctor\Doctor;
use SinaSaeedi\RuntimeMcp\Doctor\DoctorLine;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\Doctor\QueuesCheck;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\QueuePlugin;

/*
 * 01-22 Part A (spec 4.5, 7.3): the `Queues` doctor check of the queue plugin. Config only, through the same
 * WatchedQueues as queue_overview, so SQLite is enough and no queue connection is ever resolved.
 */

const QUEUES_CHECK_FIX = 'Fix runtime-mcp.queue.names, or add the connection to config/queue.php.';

/**
 * @param  array<string, mixed>  $config
 * @return list<array{string, string, string|null}>
 */
function queuesCheckResults(array $config = []): array
{
    currentTest()->bootWith($config);

    $checks = app(QueuePlugin::class)->doctorChecks();

    expect($checks)->toHaveCount(1)
        ->and($checks[0])->toBeInstanceOf(QueuesCheck::class)
        ->and($checks[0]->title())->toBe('Queues');

    return array_map(
        fn (CheckResult $result): array => [$result->status->value, $result->message, $result->fix],
        $checks[0]->run(),
    );
}

/* A healthy config, then the pass message. */
const QUEUES_CHECK_PASSES = [
    'the stock default (sync)' => [[], 'queue_overview watches 1 queue(s) on: `sync`.'],
    'a map' => [
        ['runtime-mcp.queue.names' => ['redis' => ['default', 'emails'], 'database' => ['default']]],
        'queue_overview watches 3 queue(s) on: `redis`, `database`.',
    ],
];

it('QC1 passes with the number of queues and their connections', function (string $case) {
    [$config, $message] = QUEUES_CHECK_PASSES[$case];

    expect(queuesCheckResults($config))->toBe([['pass', $message, null]]);
})->with(array_keys(QUEUES_CHECK_PASSES));

it('QC2 warns once for a names value it cannot read', function (mixed $names, string $message) {
    expect(queuesCheckResults(['runtime-mcp.queue.names' => $names]))->toBe([['warn', $message, QUEUES_CHECK_FIX]]);
})->with([
    'an empty list' => [[], 'runtime-mcp.queue.names is an empty list, so no queue is watched.'],
    'a string' => ['default', 'runtime-mcp.queue.names must be null, a list of queue names, or a map of connection names to lists of queue names.'],
]);

it('QC3 warns for each entry it cannot watch, by name only when it is name-shaped, and says nothing of the good ones', function () {
    expect(queuesCheckResults(['runtime-mcp.queue.names' => [
        'redis' => ['default'],
        'beanstalk' => ['default'],
        'has space' => ['default'],
        'database' => ['https://example.test/123456789012/q'],
    ]]))->toBe([
        ['warn', 'Connection `beanstalk` is not in config/queue.php.', QUEUES_CHECK_FIX],
        ['warn', 'The connection at position 3 of runtime-mcp.queue.names is not a connection name.', QUEUES_CHECK_FIX],
        ['warn', 'The queue at position 1 of `database` in runtime-mcp.queue.names is not a queue name.', QUEUES_CHECK_FIX],
    ]);
});

it('QC4 warns once per watched sqs connection: queue_overview does not read SQS', function () {
    expect(queuesCheckResults(['runtime-mcp.queue.names' => ['sqs' => ['default', 'emails'], 'redis' => ['default']]]))->toBe([
        ['warn', 'Connection `sqs` is watched, but queue_overview does not read SQS in v1.0.0.', 'Watch another connection, or read the size of the SQS queue in the AWS console.'],
    ]);
});

it('QC5 resolves no queue connection', function () {
    queuesCheckResults(['queue.default' => 'database', 'runtime-mcp.queue.names' => ['database' => ['default'], 'sqs' => ['default']]]);

    $manager = app(QueueManager::class);

    foreach (['database', 'sqs', 'sync', 'redis', 'beanstalkd', 'deferred', 'failover'] as $name) {
        expect($manager->connected($name))->toBeFalse("doctor resolved [{$name}]");
    }
});

it('QC6 is part of the doctor report while the queue plugin is on, and gone when it is off', function () {
    $titles = fn (): array => array_map(fn (DoctorLine $line): string => $line->title, app(Doctor::class)->run()->lines);

    currentTest()->bootWith();
    expect($titles())->toContain('Queues');

    currentTest()->bootWith(['runtime-mcp.plugins.queue' => false]);
    expect($titles())->not->toContain('Queues');
});
