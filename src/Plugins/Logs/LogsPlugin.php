<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins\Logs;

use SinaSaeedi\RuntimeMcp\Plugins\AbstractPlugin;

/** Base plugin (spec 4.2): no package needed, on by default. Tools: logs_search, logs_recent_errors (01-25, 01-26). */
final class LogsPlugin extends AbstractPlugin
{
    public function name(): string
    {
        return 'logs';
    }

    public function description(): string
    {
        return 'Search the log files and group recent errors.';
    }

    public function enabledByDefault(): bool
    {
        return true;
    }

    public function toolPrefix(): string
    {
        return 'logs_';
    }

    public function requiresReadOnlyConnection(): bool
    {
        return false;
    }
}
