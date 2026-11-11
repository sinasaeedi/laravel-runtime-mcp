<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins\Logs;

use Illuminate\Contracts\Config\Repository;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use SinaSaeedi\RuntimeMcp\Audit\AuditChannel;

/**
 * Spec 6.9, 01-25 Part A: a channel of runtime-mcp.logs.channels as file sources and reasons, read from
 * logging.channels only. Never Log::channel(): it builds the handlers, and a slack or papertrail handler can
 * connect out (rule 4). The rules follow LogManager (Laravel 12.69 and 13.x), not the channel names: the stock
 * stderr, null and papertrail channels all have driver `monolog`.
 */
final class LogChannelResolver
{
    /** A stack inside a stack inside a stack: deeper is refused (a loop ends earlier, each name is read once). */
    public const MAX_DEPTH = 3;

    public const NOT_DEFINED = '%s is not defined in config/logging.php.';

    public const NO_DRIVER = '%s has no driver in config/logging.php.';

    public const BAD_CHANNELS = '%s has a channels list that this tool cannot read.';

    public const NO_CHANNELS = '%s has no channels.';

    public const TOO_DEEP = '%s is nested more than '.self::MAX_DEPTH.' stacks deep.';

    public const NO_PATH = '%s has no path in config/logging.php.';

    public const STDERR = '%s writes to stderr, so there is no file to read.';

    public const STDOUT = '%s writes to stdout, so there is no file to read.';

    public const STREAM = '%s writes to a stream, not a file.';

    public const SYSTEM_LOG = '%s writes to the system log, so there is no file to read.';

    public const SLACK = '%s sends entries to Slack, so there is no file to read.';

    public const DISCARDS = '%s discards every entry, so there is nothing to read.';

    /** %1$s the channel, %2$s the handler's short class name. */
    public const HANDLER = '%1$s uses the Monolog handler `%2$s`; this tool reads only the single and daily drivers.';

    public const SOME_HANDLER = '%s uses a Monolog handler; this tool reads only the single and daily drivers.';

    /** %1$s the channel, %2$s the driver. */
    public const DRIVER = '%1$s uses driver `%2$s`; this tool reads only the single and daily drivers.';

    public const SOME_DRIVER = '%s uses an unknown driver; this tool reads only the single and daily drivers.';

    public const FORMATTER = "%s uses a custom formatter; only Laravel's default line format and Monolog's JsonFormatter are supported.";

    public function __construct(private readonly Repository $config) {}

    public function resolve(string $channel): ResolvedChannel
    {
        $sources = [];
        $skipped = [];
        $seen = [];
        $this->walk($channel, 0, $seen, $sources, $skipped);

        return new ResolvedChannel($sources, $skipped);
    }

    /** "Channel `daily`", or "A channel" for a name that does not have the shape of a name. */
    public static function shown(string $channel): string
    {
        return preg_match(LogSettings::NAME, $channel) === 1 ? "Channel `{$channel}`" : 'A channel';
    }

    /**
     * True when the channel writes to the package's audit log (LogsCheck warns: the audit holds every principal's
     * params, spec 5.7). Compares the audit channel's own path; the app may define the audit channel itself.
     */
    public function isAuditLog(LogSource $source): bool
    {
        $audit = $this->definition(AuditChannel::name($this->config));
        $path = is_array($audit) ? ($audit['path'] ?? null) : null;

        return is_string($path) && $path !== '' && $path === $source->path;
    }

    /**
     * @param  array<string, true>  $seen
     * @param  list<LogSource>  $sources
     * @param  list<string>  $skipped
     */
    private function walk(string $name, int $depth, array &$seen, array &$sources, array &$skipped): void
    {
        if (isset($seen[$name])) {
            return;
        }

        $seen[$name] = true;
        $shown = self::shown($name);
        $config = $this->definition($name);

        if (! is_array($config)) {
            $skipped[] = sprintf(self::NOT_DEFINED, $shown);

            return;
        }

        $driver = $config['driver'] ?? null;

        if (! is_string($driver) || $driver === '') {
            $skipped[] = sprintf(self::NO_DRIVER, $shown);

            return;
        }

        match ($driver) {
            'stack' => $this->stack($name, $config, $depth, $seen, $sources, $skipped),
            'single', 'daily' => $this->file($name, $config, $driver === 'daily', $sources, $skipped),
            'monolog' => $skipped[] = $this->monolog($shown, $config),
            'syslog', 'errorlog' => $skipped[] = sprintf(self::SYSTEM_LOG, $shown),
            'slack' => $skipped[] = sprintf(self::SLACK, $shown),
            default => $skipped[] = preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $driver) === 1
                ? sprintf(self::DRIVER, $shown, $driver)
                : sprintf(self::SOME_DRIVER, $shown),
        };
    }

    /**
     * As LogManager::createStackDriver(): a string is split on commas, with no trim.
     *
     * @param  array<array-key, mixed>  $config
     * @param  array<string, true>  $seen
     * @param  list<LogSource>  $sources
     * @param  list<string>  $skipped
     */
    private function stack(string $name, array $config, int $depth, array &$seen, array &$sources, array &$skipped): void
    {
        $shown = self::shown($name);

        if ($depth >= self::MAX_DEPTH) {
            $skipped[] = sprintf(self::TOO_DEEP, $shown);

            return;
        }

        $channels = $config['channels'] ?? null;
        $channels = is_string($channels) ? explode(',', $channels) : $channels;

        if (! is_array($channels) || ! array_is_list($channels) || array_filter($channels, fn (mixed $child): bool => ! is_string($child)) !== []) {
            $skipped[] = sprintf(self::BAD_CHANNELS, $shown);

            return;
        }

        if ($channels === []) {
            $skipped[] = sprintf(self::NO_CHANNELS, $shown);

            return;
        }

        foreach ($channels as $child) {
            assert(is_string($child));
            $this->walk($child, $depth + 1, $seen, $sources, $skipped);
        }
    }

    /**
     * @param  array<array-key, mixed>  $config
     * @param  list<LogSource>  $sources
     * @param  list<string>  $skipped
     */
    private function file(string $name, array $config, bool $daily, array &$sources, array &$skipped): void
    {
        $shown = self::shown($name);
        $path = $config['path'] ?? null;

        if (! is_string($path) || $path === '') {
            $skipped[] = sprintf(self::NO_PATH, $shown);

            return;
        }

        if (str_contains($path, '://')) {
            $skipped[] = self::stream($shown, $path);

            return;
        }

        $format = self::format($config['formatter'] ?? null);

        if ($format === null) {
            $skipped[] = sprintf(self::FORMATTER, $shown);

            return;
        }

        $sources[] = new LogSource($name, $path, $format, $daily, $daily ? self::maxFiles($config) : 1);
    }

    /** @param  array<array-key, mixed>  $config */
    private function monolog(string $shown, array $config): string
    {
        if (AuditChannel::discards($config)) {
            return sprintf(self::DISCARDS, $shown);
        }

        $handler = $config['handler'] ?? null;

        if (! is_string($handler) || $handler === '') {
            return sprintf(self::SOME_HANDLER, $shown);
        }

        if (is_a($handler, StreamHandler::class, true)) {
            // LogManager::createMonologDriver(): array_merge($with, $handlerWith), so handler_with wins.
            $with = is_array($config['with'] ?? null) ? $config['with'] : [];
            $handlerWith = is_array($config['handler_with'] ?? null) ? $config['handler_with'] : [];
            $stream = $handlerWith['stream'] ?? $with['stream'] ?? null;

            if (is_string($stream) && str_contains($stream, '://')) {
                return self::stream($shown, $stream);
            }
        }

        $short = substr((string) strrchr('\\'.ltrim($handler, '\\'), '\\'), 1);

        return preg_match('/^[A-Za-z0-9_]{1,64}$/D', $short) === 1
            ? sprintf(self::HANDLER, $shown, $short)
            : sprintf(self::SOME_HANDLER, $shown);
    }

    private static function stream(string $shown, string $stream): string
    {
        return match (strtolower($stream)) {
            'php://stderr' => sprintf(self::STDERR, $shown),
            'php://stdout', 'php://output' => sprintf(self::STDOUT, $shown),
            default => sprintf(self::STREAM, $shown),
        };
    }

    /**
     * As LogManager::prepareHandler(): no formatter, null or 'default' is Laravel's line format. Only the exact
     * JsonFormatter: its subclasses (GoogleCloudLoggingFormatter, LogglyFormatter, LogmaticFormatter) rename
     * `datetime` and `level_name`.
     *
     * @return LogSource::LINE|LogSource::JSON|null
     */
    private static function format(mixed $formatter): ?string
    {
        return match (true) {
            $formatter === null, $formatter === 'default' => LogSource::LINE,
            is_string($formatter) && ltrim($formatter, '\\') === JsonFormatter::class => LogSource::JSON,
            default => null,
        };
    }

    /**
     * Laravel 13 reads max_files ?? days ?? 7 (12.69 reads days ?? 7). A digit string counts: env() gives strings, and
     * LogManager passes the value to RotatingFileHandler's int parameter. 0 (unlimited) and other values give 14.
     *
     * @param  array<array-key, mixed>  $config
     */
    private static function maxFiles(array $config): int
    {
        $value = $config['max_files'] ?? $config['days'] ?? 7;

        if (is_string($value) && preg_match('/^[0-9]{1,6}$/D', $value) === 1) {
            $value = (int) $value;
        }

        return is_int($value) && $value >= 1 && $value <= 1_000_000 ? $value : LogSource::DEFAULT_DAILY_FILES;
    }

    /** One channel's config. A name with a dot is one key here, not a config path. */
    private function definition(string $name): mixed
    {
        $channels = $this->config->get('logging.channels');

        return is_array($channels) ? ($channels[$name] ?? null) : null;
    }
}
