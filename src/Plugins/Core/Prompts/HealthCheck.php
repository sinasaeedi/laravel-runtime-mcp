<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins\Core\Prompts;

use SinaSaeedi\RuntimeMcp\Prompts\Contracts\RuntimePrompt;
use SinaSaeedi\RuntimeMcp\Prompts\PromptInput;
use SinaSaeedi\RuntimeMcp\Prompts\PromptText;

/**
 * Spec 6.11: the health check (core plugin). It needs queue_overview too, so with the queue plugin off it
 * never shows: that is spec 6.11's list. Steps 3 and 4 name a tool only when the Gate allows it.
 */
final class HealthCheck extends RuntimePrompt
{
    public function name(): string
    {
        return 'health_check';
    }

    public function title(): string
    {
        return 'Health check';
    }

    public function description(): string
    {
        return 'A short health check of this server: app state, queues, failed jobs and recent errors.';
    }

    public function tools(): array
    {
        return ['app_info', 'queue_overview'];
    }

    public function message(PromptInput $input): string
    {
        $context = $input->context;

        return PromptText::compose(
            "Give a short health check of the `{$context->environment()}` environment of `{$context->appName()}`. This server is read-only.",
            PromptText::steps([
                'Call `app_info`. Note maintenance mode, the debug flag, and whether config and routes are cached.',
                'Call `queue_overview`. Note any queue whose `status` is not `ok`, and any queue with a large `size`.',
                $context->allows('queue_failed_jobs_summary')
                    ? 'Call `queue_failed_jobs_summary` with no `from` and no `to` (the last 24 hours).'
                    : null,
                $context->allows('logs_recent_errors')
                    ? 'Call `logs_recent_errors` with `since` set to one hour before now, as an ISO 8601 date-time.'
                    : null,
            ])."\nThen report one table with a status per area (ok, check, unknown) and one line of evidence each.",
        );
    }
}
