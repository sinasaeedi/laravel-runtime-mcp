<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins\Logs;

use Illuminate\Contracts\Config\Repository;
use SinaSaeedi\RuntimeMcp\Plugins\AbstractPlugin;
use SinaSaeedi\RuntimeMcp\Plugins\Logs\Doctor\LogsCheck;
use SinaSaeedi\RuntimeMcp\Plugins\Logs\Tools\LogsRecentErrors;
use SinaSaeedi\RuntimeMcp\Plugins\Logs\Tools\LogsSearch;

/** Base plugin (spec 4.2): no package needed, on by default. Tools: logs_search, logs_recent_errors (01-25, 01-26). */
final class LogsPlugin extends AbstractPlugin
{
    public function __construct(private readonly Repository $config) {}

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

    public function tools(): array
    {
        return [LogsSearch::class, LogsRecentErrors::class];
    }

    public function doctorChecks(): array
    {
        return [new LogsCheck($this->config, new LogChannelResolver($this->config))];
    }
}
