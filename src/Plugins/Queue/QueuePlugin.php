<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins\Queue;

use Illuminate\Contracts\Config\Repository;
use SinaSaeedi\RuntimeMcp\Plugins\AbstractPlugin;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\Doctor\FailedJobsCheck;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\Doctor\QueuesCheck;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\Tools\QueueFailedJobsList;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\Tools\QueueFailedJobsSummary;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\Tools\QueueOverview;

/** Base plugin (spec 4.2): no package needed, on by default. Tools: queue_overview, queue_failed_jobs_list, queue_failed_jobs_summary, queue_failed_job_show (01-22 to 01-24). */
final class QueuePlugin extends AbstractPlugin
{
    public function __construct(private readonly Repository $config) {}

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

    /**
     * True while the failed jobs are in a table (01-23): the tools read it on the read-only connection, so 01-10's
     * boot rule and 01-13's privilege check must cover that connection (Sina, 2026-10-29, 01-14 re-review). Config
     * only, as every boot rule (spec 5.11). False for file, null and dynamodb: they read no table.
     */
    public function requiresReadOnlyConnection(): bool
    {
        return FailedJobSource::fromConfig($this->config)->kind === FailedJobSource::TABLE;
    }

    public function tools(): array
    {
        return [QueueOverview::class, QueueFailedJobsList::class, QueueFailedJobsSummary::class];
    }

    public function doctorChecks(): array
    {
        return [new QueuesCheck(new WatchedQueues($this->config)), new FailedJobsCheck($this->config)];
    }
}
