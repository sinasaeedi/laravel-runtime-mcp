<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Audit;

use Illuminate\Contracts\Config\Repository;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\NullHandler;

/**
 * Spec 5.7: the audit log channel. The package registers it as a daily JSON channel when the app
 * does not define it. A bad config value falls back to the default, so no value can turn the audit
 * off (rule 12).
 *
 * Static methods only, no static property (rule 14). They read the config on every call.
 */
final class AuditChannel
{
    public const DEFAULT_NAME = 'runtime-mcp';

    public const DEFAULT_DAYS = 90;

    /** Letters, digits, "_" and "-". A dot would make logging.channels.<name> a nested key. */
    private const NAME_RULE = '/^[A-Za-z0-9_-]{1,64}$/';

    /**
     * The channel name: runtime-mcp.audit.channel when it is valid, else "runtime-mcp". A name whose
     * channel drops every entry (Laravel's own "null" channel) is not valid: it would turn the audit
     * off with no error.
     */
    public static function name(Repository $config): string
    {
        $name = $config->get('runtime-mcp.audit.channel');

        if (! is_string($name) || preg_match(self::NAME_RULE, $name) !== 1) {
            return self::DEFAULT_NAME;
        }

        return self::discards($config->get("logging.channels.{$name}")) ? self::DEFAULT_NAME : $name;
    }

    /**
     * The retention in days: runtime-mcp.audit.days when it is an int of 1 or more, or a string of
     * digits (an env() value). Never 0: Monolog reads 0 as "keep every file forever".
     */
    public static function days(Repository $config): int
    {
        $days = $config->get('runtime-mcp.audit.days');

        if (is_string($days) && preg_match('/^[0-9]{1,6}$/', $days) === 1) {
            $days = (int) $days;
        }

        return is_int($days) && $days >= 1 ? $days : self::DEFAULT_DAYS;
    }

    /** Registers the package's daily JSON channel, unless the app defines one with this name. */
    public static function register(Repository $config, string $path): void
    {
        $name = self::name($config);

        if ($config->get("logging.channels.{$name}") !== null) {
            return;
        }

        $config->set("logging.channels.{$name}", [
            'driver' => 'daily',
            'path' => $path,
            'level' => 'info',
            'days' => self::days($config),
            'formatter' => JsonFormatter::class,
            'replace_placeholders' => true,
        ]);
    }

    /**
     * The monolog driver with a NullHandler: Laravel's "null" channel, which writes nothing. 01-12
     * (doctor) fails for an app's own channel of this shape, which name() cannot fall back from.
     */
    public static function discards(mixed $channel): bool
    {
        $handler = is_array($channel) && ($channel['driver'] ?? null) === 'monolog' ? $channel['handler'] ?? null : null;

        return is_string($handler) && is_a($handler, NullHandler::class, true);
    }
}
