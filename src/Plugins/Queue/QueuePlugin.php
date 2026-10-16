<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins\Queue;

use SinaSaeedi\RuntimeMcp\Plugins\AbstractPlugin;

/** Base plugin (spec 4.2): no package needed, on by default. Tools: queue_overview, queue_failed_jobs_list, queue_failed_jobs_summary, queue_failed_job_show (01-22 to 01-24). */
final class QueuePlugin extends AbstractPlugin
{
    public function name(): string
    {
        return 'queue';
    }

    public function description(): string
    {
        return 'Queue sizes and failed jobs.';
    }

    public function enabledByDefault(): bool
    {
        return true;
    }

    public function toolPrefix(): string
    {
        return 'queue_';
    }

    public function requiresReadOnlyConnection(): bool
    {
        return false;
    }
}
