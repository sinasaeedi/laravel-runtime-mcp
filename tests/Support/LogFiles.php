<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use FilesystemIterator;
use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * 01-25: log files written with the real Monolog formatters, in a temp directory of this process. Testbench's
 * storage/logs holds laravel.log and the audit files (AuditLog reads runtime-mcp-*.log there), so fixtures never go
 * there. The channels have their own names, so Laravel's own `stack`/`single` keep writing to Testbench's laravel.log.
 */
final class LogFiles
{
    public const TOOL = 'logs_search';

    /** A single channel (line format), a daily channel, a JSON single channel, all in dir(). */
    public const SINGLE = 'app_single';

    public const DAILY = 'app_daily';

    public const JSON = 'app_json';

    public const STACK = 'app_stack';

    public static function dir(): string
    {
        return sys_get_temp_dir().'/rmcp-logs-'.getmypid();
    }

    public static function path(string $name): string
    {
        return self::dir().'/'.$name;
    }

    /**
     * The four channels of this helper, for bootWith().
     *
     * @param  list<string>  $logsChannels  runtime-mcp.logs.channels
     * @param  array<string, mixed>  $more  more channels or config
     * @return array<string, mixed>
     */
    public static function config(array $logsChannels = [self::SINGLE], array $more = []): array
    {
        return [
            'runtime-mcp.enabled' => true,
            'cache.limiter' => 'array',
            'runtime-mcp.logs.channels' => $logsChannels,
            'logging.channels.'.self::SINGLE => ['driver' => 'single', 'path' => self::path('app.log')],
            'logging.channels.'.self::DAILY => ['driver' => 'daily', 'path' => self::path('app.log'), 'days' => 14],
            'logging.channels.'.self::JSON => ['driver' => 'single', 'path' => self::path('app-json.log'), 'formatter' => JsonFormatter::class],
            'logging.channels.'.self::STACK => ['driver' => 'stack', 'channels' => [self::SINGLE, self::JSON]],
            ...$more,
        ];
    }

    /**
     * One entry as Laravel's default formatter writes it (LogManager::formatter()), "\n" included.
     *
     * @param  'debug'|'info'|'notice'|'warning'|'error'|'critical'|'alert'|'emergency'  $level
     * @param  array<array-key, mixed>  $context
     * @param  array<array-key, mixed>  $extra
     */
    public static function line(string $time, string $level, string $message, array $context = [], array $extra = [], string $env = 'testing'): string
    {
        return (new LineFormatter(null, 'Y-m-d H:i:s', true, true, true))->format(self::record($time, $level, $message, $context, $extra, $env));
    }

    /**
     * One entry as Monolog's own LineFormatter writes it: what a channel with `formatter => 'default'` keeps (an
     * offset stamp with microseconds, one line, an empty context and extra as "[]").
     *
     * @param  'debug'|'info'|'notice'|'warning'|'error'|'critical'|'alert'|'emergency'  $level
     * @param  array<array-key, mixed>  $context
     * @param  array<array-key, mixed>  $extra
     */
    public static function monologLine(string $time, string $level, string $message, array $context = [], array $extra = [], string $env = 'testing'): string
    {
        return (new LineFormatter)->format(self::record($time, $level, $message, $context, $extra, $env));
    }

    /**
     * One entry as Monolog's JsonFormatter writes it, "\n" included.
     *
     * @param  'debug'|'info'|'notice'|'warning'|'error'|'critical'|'alert'|'emergency'  $level
     * @param  array<array-key, mixed>  $context
     * @param  array<array-key, mixed>  $extra
     */
    public static function json(string $time, string $level, string $message, array $context = [], array $extra = [], string $env = 'testing'): string
    {
        return (new JsonFormatter)->format(self::record($time, $level, $message, $context, $extra, $env));
    }

    /**
     * Writes the entries, oldest first, as the file's whole content.
     *
     * @param  list<string>  $entries
     */
    public static function write(string $name, array $entries): string
    {
        if (! is_dir(self::dir())) {
            mkdir(self::dir(), 0755, true);
        }

        file_put_contents(self::path($name), implode('', $entries));

        return self::path($name);
    }

    public static function clear(): void
    {
        if (! is_dir(self::dir())) {
            return;
        }

        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::dir(), FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($items as $item) {
            assert($item instanceof \SplFileInfo);
            chmod($item->getPathname(), 0755);
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir(self::dir());
    }

    /**
     * @param  'debug'|'info'|'notice'|'warning'|'error'|'critical'|'alert'|'emergency'  $level
     * @param  array<array-key, mixed>  $context
     * @param  array<array-key, mixed>  $extra
     */
    private static function record(string $time, string $level, string $message, array $context, array $extra, string $env): LogRecord
    {
        $timezone = new DateTimeZone(date_default_timezone_get());

        return new LogRecord(new DateTimeImmutable($time, $timezone), $env, Level::fromName($level), $message, $context, $extra);
    }
}
