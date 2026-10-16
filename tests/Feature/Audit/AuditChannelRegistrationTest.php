<?php

declare(strict_types=1);

use Illuminate\Log\Logger as LaravelLogger;
use Illuminate\Log\LogManager;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger as MonologLogger;
use SinaSaeedi\RuntimeMcp\Audit\AuditEntry;
use SinaSaeedi\RuntimeMcp\Audit\AuditLogger;
use SinaSaeedi\RuntimeMcp\Audit\Reason;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;

beforeEach(fn () => AuditLog::clear());
afterEach(fn () => AuditLog::clear());

/** The Monolog handler behind a channel, to prove what the config reaches. */
function auditChannelHandler(string $name): RotatingFileHandler
{
    $logger = app(LogManager::class)->channel($name);
    assert($logger instanceof LaravelLogger);

    $monolog = $logger->getLogger();
    assert($monolog instanceof MonologLogger);

    $handler = $monolog->getHandlers()[0] ?? null;
    assert($handler instanceof RotatingFileHandler);

    return $handler;
}

function auditChannelMaxFiles(RotatingFileHandler $handler): int
{
    $maxFiles = (new ReflectionProperty(RotatingFileHandler::class, 'maxFiles'))->getValue($handler);
    assert(is_int($maxFiles));

    return $maxFiles;
}

it('registers the daily JSON channel while the endpoint is off', function () {
    expect(config('runtime-mcp.enabled'))->toBeFalse()
        ->and(config('logging.channels.runtime-mcp'))->toBe([
            'driver' => 'daily',
            'path' => storage_path('logs/runtime-mcp.log'),
            'level' => 'info',
            'days' => 90,
            'formatter' => JsonFormatter::class,
            'replace_placeholders' => true,
        ]);

    $handler = auditChannelHandler('runtime-mcp');

    expect($handler->getFormatter())->toBeInstanceOf(JsonFormatter::class)
        ->and(auditChannelMaxFiles($handler))->toBe(90);
});

it('passes audit.days to Monolog as the number of files to keep', function (mixed $days, int $expected) {
    currentTest()->bootWith(['runtime-mcp.audit.days' => $days]);

    expect(config('logging.channels.runtime-mcp.days'))->toBe($expected)
        ->and(auditChannelMaxFiles(auditChannelHandler('runtime-mcp')))->toBe($expected);
})->with([
    'an int' => [30, 30],
    'an env() string' => ['14', 14],
    'zero falls back' => [0, 90],
]);

it('never changes a channel that the app defines', function () {
    $appChannel = ['driver' => 'single', 'path' => '/var/log/app-audit.log', 'level' => 'debug'];

    currentTest()->bootWith(['logging.channels.runtime-mcp' => $appChannel]);

    expect(config('logging.channels.runtime-mcp'))->toBe($appChannel);
});

it('registers the channel under the name in audit.channel', function () {
    currentTest()->bootWith(['runtime-mcp.audit.channel' => 'security']);

    expect(config('logging.channels.security.driver'))->toBe('daily')
        ->and(config('logging.channels.runtime-mcp'))->toBeNull();
});

it('registers runtime-mcp when audit.channel is not a valid name', function () {
    currentTest()->bootWith(['runtime-mcp.audit.channel' => 'audit.security']);

    expect(config('logging.channels.runtime-mcp.driver'))->toBe('daily')
        ->and(config('logging.channels.audit'))->toBeNull();
});

it('writes one JSON object per line to storage/logs/runtime-mcp-<date>.log', function () {
    $logger = app(AuditLogger::class);

    $logger->rejectedRequest(AuditEntry::rejectedRequest('testing', Reason::BadHost, null, '10.0.0.1', ['host' => 'evil.example']));
    $logger->rejectedRequest(AuditEntry::rejectedRequest('testing', Reason::BadOrigin, null, '10.0.0.2', ['origin' => 'https://evil.example']));

    $files = AuditLog::files();

    expect($files)->toHaveCount(1)
        ->and(basename($files[0]))->toMatch('/^runtime-mcp-\d{4}-\d{2}-\d{2}\.log$/')
        ->and(AuditLog::lines())->toHaveCount(2)
        ->and(array_column(AuditLog::entries(), 'reason'))->toBe(['bad_host', 'bad_origin']);
});
