<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins\Queue\Prompts;

use SinaSaeedi\RuntimeMcp\Prompts\Contracts\RuntimePrompt;
use SinaSaeedi\RuntimeMcp\Prompts\PromptArgument;
use SinaSaeedi\RuntimeMcp\Prompts\PromptContext;
use SinaSaeedi\RuntimeMcp\Prompts\PromptInput;
use SinaSaeedi\RuntimeMcp\Prompts\PromptText;

/**
 * Spec 6.11: the failed-jobs playbook (queue plugin). The windows are the tools' own: the summary's
 * default is the last 24 hours, and every time is ISO 8601 (01-23, 01-24, 01-25).
 */
final class InvestigateFailedJobs extends RuntimePrompt
{
    /** A job class as the agent types it: letters, digits, `_` and `\`, nothing that ends a code span. */
    public const JOB_CLASS_PATTERN = '/^[A-Za-z0-9_\\\\]{1,200}$/D';

    public function name(): string
    {
        return 'investigate_failed_jobs';
    }

    public function title(): string
    {
        return 'Investigate failed jobs';
    }

    public function description(): string
    {
        return 'Find which queue jobs fail and the most likely cause, with read-only tools.';
    }

    public function arguments(PromptContext $context): array
    {
        return [
            new PromptArgument('since', 'How far back to look: a number and h or d, for example 24h or 7d. Default 24h.', rules: ['regex:'.PromptText::SINCE_PATTERN]),
            new PromptArgument('job_class', 'Only this job class, for example App\Jobs\SendInvoice.', rules: ['regex:'.self::JOB_CLASS_PATTERN]),
        ];
    }

    public function tools(): array
    {
        return ['queue_failed_jobs_summary', 'queue_failed_jobs_list', 'queue_failed_job_show'];
    }

    public function message(PromptInput $input): string
    {
        $context = $input->context;
        $since = $input->get('since') ?? PromptText::DEFAULT_SINCE;
        $jobClass = $input->get('job_class');
        $window = $since === PromptText::DEFAULT_SINCE
            ? 'with no `from` and no `to` (the last 24 hours)'
            : 'with `from` set to '.PromptText::duration($since).' before now, as an ISO 8601 date-time';
        $target = $jobClass === null ? 'the job class with the most failures' : "the job class `{$jobClass}`";

        return PromptText::compose(
            "Investigate the failed queue jobs on the `{$context->environment()}` environment of `{$context->appName()}`. This server is read-only.",
            PromptText::steps([
                "Call `queue_failed_jobs_summary` {$window}. Its `data.from` and `data.to` show the window the server used; use the same `from` and `to` in the next calls.",
                "Take {$target}. Call `queue_failed_jobs_list` with that `job_class`, the same `from` and `to`, and `limit` 10.",
                'Call `queue_failed_job_show` with the `id` of the newest failure, unchanged. Call it once more for an older failure if its exception differs.',
                $context->allows('logs_search')
                    ? 'Call `logs_search` with `level` `error`, `from` five minutes before and `to` five minutes after the newest `failed_at`.'
                    : null,
            ])."\nThen report the failing job classes with their counts, and the most likely cause with its evidence.",
            'Do not offer to retry or delete jobs.',
        );
    }
}
