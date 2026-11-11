<?php

declare(strict_types=1);

use Monolog\Formatter\GoogleCloudLoggingFormatter;
use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use SinaSaeedi\RuntimeMcp\Plugins\Logs\LogChannelResolver;
use SinaSaeedi\RuntimeMcp\Plugins\Logs\LogSource;
use SinaSaeedi\RuntimeMcp\Plugins\Logs\ResolvedChannel;

/*
 * 01-25 Part A (spec 6.9): channels to file sources and reasons, from logging.channels only. The stock channels
 * (stderr, null, papertrail, syslog, errorlog, slack, emergency) are Testbench's copy of Laravel's own
 * config/logging.php, so these rows prove the real definitions.
 */

/** @param  array<string, mixed>  $channels  logging.channels.<name> => config, merged into Laravel's own */
function logChannelResolve(string $channel, array $channels = []): ResolvedChannel
{
    foreach ($channels as $name => $config) {
        config()->set('logging.channels', [...(array) config('logging.channels'), $name => $config]);
    }

    return (new LogChannelResolver(config()))->resolve($channel);
}

/** @return list<array{string, string, string, bool, int}> */
function logChannelSources(ResolvedChannel $resolved): array
{
    return array_map(
        fn (LogSource $source): array => [$source->channel, $source->path, $source->format, $source->daily, $source->maxFiles],
        $resolved->sources,
    );
}

it('LR1 reads a single and a daily channel, in the line format or the JSON format', function (array $config, string $format) {
    $resolved = logChannelResolve('x', ['x' => ['driver' => 'single', 'path' => '/var/log/app.log', ...$config]]);

    expect(logChannelSources($resolved))->toBe([['x', '/var/log/app.log', $format, false, 1]])
        ->and($resolved->skipped)->toBe([]);
})->with([
    'no formatter' => [[], LogSource::LINE],
    'formatter null (the stock LOG_STDERR_FORMATTER unset)' => [['formatter' => null], LogSource::LINE],
    "formatter 'default' (LogManager::prepareHandler())" => [['formatter' => 'default'], LogSource::LINE],
    'JsonFormatter' => [['formatter' => JsonFormatter::class], LogSource::JSON],
    'JsonFormatter with a leading backslash' => [['formatter' => '\\'.JsonFormatter::class], LogSource::JSON],
]);

it('LR2 takes the daily file count as Laravel 13 does: max_files, then days, then 7; 0 and other values give 14', function (array $config, int $files) {
    $resolved = logChannelResolve('x', ['x' => ['driver' => 'daily', 'path' => '/var/log/app.log', ...$config]]);

    expect(logChannelSources($resolved))->toBe([['x', '/var/log/app.log', LogSource::LINE, true, $files]]);
})->with([
    'max_files (Laravel 13 stock)' => [['max_files' => 30, 'days' => 5], 30],
    'days (Laravel 12 stock)' => [['days' => 5], 5],
    'max_files null falls to days' => [['max_files' => null, 'days' => 9], 9],
    'days as an env() string' => [['days' => '21'], 21],
    'neither: Laravel keeps 7' => [[], 7],
    'days 0 (Monolog: unlimited)' => [['days' => 0], 14],
    'days not a number' => [['days' => 'many'], 14],
    'days negative' => [['days' => -3], 14],
]);

/** Channel config => the one reason. Stock channels have a null config here (Testbench defines them). */
const LOG_CHANNEL_SKIPPED = [
    'not defined' => ['nope', null, 'Channel `nope` is not defined in config/logging.php.'],
    'stock emergency (no driver)' => ['emergency', null, 'Channel `emergency` has no driver in config/logging.php.'],
    'stock stderr (handler_with)' => ['stderr', null, 'Channel `stderr` writes to stderr, so there is no file to read.'],
    'stderr through with' => ['x', ['driver' => 'monolog', 'handler' => StreamHandler::class, 'with' => ['stream' => 'php://stderr']], 'Channel `x` writes to stderr, so there is no file to read.'],
    'handler_with wins over with' => ['x', ['driver' => 'monolog', 'handler' => StreamHandler::class, 'with' => ['stream' => 'php://stderr'], 'handler_with' => ['stream' => 'php://stdout']], 'Channel `x` writes to stdout, so there is no file to read.'],
    'a StreamHandler to a file' => ['x', ['driver' => 'monolog', 'handler' => StreamHandler::class, 'handler_with' => ['stream' => '/var/log/x.log']], 'Channel `x` uses the Monolog handler `StreamHandler`; this tool reads only the single and daily drivers.'],
    'stock null (NullHandler)' => ['null', null, 'Channel `null` discards every entry, so there is nothing to read.'],
    'stock papertrail (SyslogUdpHandler)' => ['papertrail', null, 'Channel `papertrail` uses the Monolog handler `SyslogUdpHandler`; this tool reads only the single and daily drivers.'],
    'a monolog channel without a handler' => ['x', ['driver' => 'monolog'], 'Channel `x` uses a Monolog handler; this tool reads only the single and daily drivers.'],
    'stock syslog' => ['syslog', null, 'Channel `syslog` writes to the system log, so there is no file to read.'],
    'stock errorlog' => ['errorlog', null, 'Channel `errorlog` writes to the system log, so there is no file to read.'],
    'stock slack' => ['slack', null, 'Channel `slack` sends entries to Slack, so there is no file to read.'],
    'custom' => ['x', ['driver' => 'custom', 'via' => 'App\\Logging\\Make'], 'Channel `x` uses driver `custom`; this tool reads only the single and daily drivers.'],
    'monthly (Laravel 13)' => ['x', ['driver' => 'monthly', 'path' => '/var/log/app.log'], 'Channel `x` uses driver `monthly`; this tool reads only the single and daily drivers.'],
    'a driver that is not a name' => ['x', ['driver' => 'a b'], 'Channel `x` uses an unknown driver; this tool reads only the single and daily drivers.'],
    'a JsonFormatter subclass' => ['x', ['driver' => 'single', 'path' => '/var/log/app.log', 'formatter' => GoogleCloudLoggingFormatter::class], "Channel `x` uses a custom formatter; only Laravel's default line format and Monolog's JsonFormatter are supported."],
    'another formatter' => ['x', ['driver' => 'daily', 'path' => '/var/log/app.log', 'formatter' => LineFormatter::class], "Channel `x` uses a custom formatter; only Laravel's default line format and Monolog's JsonFormatter are supported."],
    'no path' => ['x', ['driver' => 'single'], 'Channel `x` has no path in config/logging.php.'],
    'a single path to php://stderr' => ['x', ['driver' => 'single', 'path' => 'php://stderr'], 'Channel `x` writes to stderr, so there is no file to read.'],
    'a single path to another stream' => ['x', ['driver' => 'single', 'path' => 's3://bucket/app.log'], 'Channel `x` writes to a stream, not a file.'],
    'not an array' => ['x', 'single', 'Channel `x` is not defined in config/logging.php.'],
];

it('LR3 gives one reason, and no source, for a channel without a file it can read', function (string $case) {
    [$channel, $config, $reason] = LOG_CHANNEL_SKIPPED[$case];
    $resolved = logChannelResolve($channel, $config === null ? [] : [$channel => $config]);

    expect($resolved->sources)->toBe([])
        ->and($resolved->skipped)->toBe([$reason]);
})->with(array_keys(LOG_CHANNEL_SKIPPED));

it('LR4 resolves a stack in order, splits a string on commas, and reads each channel once', function () {
    $resolved = logChannelResolve('top', [
        'top' => ['driver' => 'stack', 'channels' => 'a,stderr,b'],
        'a' => ['driver' => 'single', 'path' => '/l/a.log'],
        'b' => ['driver' => 'stack', 'channels' => ['c', 'a', 'top']],
        'c' => ['driver' => 'daily', 'path' => '/l/c.log', 'days' => 3],
    ]);

    expect(logChannelSources($resolved))->toBe([
        ['a', '/l/a.log', LogSource::LINE, false, 1],
        ['c', '/l/c.log', LogSource::LINE, true, 3],
    ])->and($resolved->skipped)->toBe(['Channel `stderr` writes to stderr, so there is no file to read.']);
});

it('LR5 ends a loop and refuses a stack deeper than three', function () {
    $loop = logChannelResolve('a', [
        'a' => ['driver' => 'stack', 'channels' => ['b']],
        'b' => ['driver' => 'stack', 'channels' => ['a']],
    ]);
    $deep = logChannelResolve('s0', [
        's0' => ['driver' => 'stack', 'channels' => ['s1']],
        's1' => ['driver' => 'stack', 'channels' => ['s2']],
        's2' => ['driver' => 'stack', 'channels' => ['s3', 'f']],
        's3' => ['driver' => 'stack', 'channels' => ['f']],
        'f' => ['driver' => 'single', 'path' => '/l/f.log'],
    ]);

    expect($loop->sources)->toBe([])
        ->and($loop->skipped)->toBe([])
        ->and($deep->skipped)->toBe(['Channel `s3` is nested more than 3 stacks deep.'])
        ->and(logChannelSources($deep))->toBe([['f', '/l/f.log', LogSource::LINE, false, 1]]);
});

it('LR6 refuses a channels list it cannot read, and an empty one', function (mixed $channels, string $reason) {
    $resolved = logChannelResolve('s', ['s' => ['driver' => 'stack', 'channels' => $channels]]);

    expect($resolved->sources)->toBe([])
        ->and($resolved->skipped)->toBe([$reason]);
})->with([
    'missing' => [null, 'Channel `s` has a channels list that this tool cannot read.'],
    'not a list' => [['x' => 'single'], 'Channel `s` has a channels list that this tool cannot read.'],
    'a non-string entry' => [['single', 3], 'Channel `s` has a channels list that this tool cannot read.'],
    'empty' => [[], 'Channel `s` has no channels.'],
]);

it('LR7 shows a name only when it has the shape of a name, and reads a dotted name as one key', function () {
    $odd = logChannelResolve('a b', ['a b' => ['driver' => 'syslog']]);
    $dotted = logChannelResolve('app.v2', ['app.v2' => ['driver' => 'single', 'path' => '/l/v2.log']]);

    expect($odd->skipped)->toBe(['A channel writes to the system log, so there is no file to read.'])
        ->and(LogChannelResolver::shown('daily'))->toBe('Channel `daily`')
        ->and(LogChannelResolver::shown(str_repeat('x', 65)))->toBe('A channel')
        ->and(logChannelSources($dotted))->toBe([['app.v2', '/l/v2.log', LogSource::LINE, false, 1]]);
});

it('LR8 knows the package audit log by its path', function () {
    $resolver = new LogChannelResolver(config());
    $audit = config('logging.channels.runtime-mcp.path');
    assert(is_string($audit));

    expect($resolver->isAuditLog(new LogSource('runtime-mcp', $audit, LogSource::JSON, true, 90)))->toBeTrue()
        ->and($resolver->isAuditLog(new LogSource('x', '/l/other.log', LogSource::LINE, false)))->toBeFalse();
});
