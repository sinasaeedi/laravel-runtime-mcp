<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins\Logs\Prompts;

use SinaSaeedi\RuntimeMcp\Prompts\Contracts\RuntimePrompt;
use SinaSaeedi\RuntimeMcp\Prompts\PromptArgument;
use SinaSaeedi\RuntimeMcp\Prompts\PromptContext;
use SinaSaeedi\RuntimeMcp\Prompts\PromptInput;
use SinaSaeedi\RuntimeMcp\Prompts\PromptText;

/**
 * Spec 6.11: the recent-errors playbook (logs plugin). logs_recent_errors takes `since` as ISO 8601
 * (01-26), and its groups hold `sample.message`, `first_seen` and `last_seen` already.
 */
final class TriageRecentErrors extends RuntimePrompt
{
    public function name(): string
    {
        return 'triage_recent_errors';
    }

    public function title(): string
    {
        return 'Triage recent errors';
    }

    public function description(): string
    {
        return 'Group the recent errors in the logs by cause, with counts and a likely cause for each.';
    }

    public function arguments(PromptContext $context): array
    {
        return [
            new PromptArgument('since', 'How far back to look: a number and h or d, for example 24h or 7d. Default 24h.', rules: ['regex:'.PromptText::SINCE_PATTERN]),
            new PromptArgument('channel', 'The log channel to read. Default: the first configured channel.', allowed: $context->logChannels()),
        ];
    }

    public function tools(): array
    {
        return ['logs_recent_errors', 'logs_search'];
    }

    public function message(PromptInput $input): string
    {
        $context = $input->context;
        $since = $input->get('since') ?? PromptText::DEFAULT_SINCE;
        $channel = $input->get('channel');
        $window = $since === PromptText::DEFAULT_SINCE
            ? 'with no `since` (the last 24 hours)'
            : 'with `since` set to '.PromptText::duration($since).' before now, as an ISO 8601 date-time';
        $onChannel = $channel === null ? '' : " and `channel` `{$channel}`";

        return PromptText::compose(
            "Triage the recent errors on the `{$context->environment()}` environment of `{$context->appName()}`. This server is read-only.",
            PromptText::steps([
                "Call `logs_recent_errors` {$window}{$onChannel}. Its `data.since` shows the window the server used.",
                "For each of the three largest groups, call `logs_search` with `contains` set to a short, exact part of `sample.message` (at most 200 characters){$onChannel}, and no `from`, to see the full entries and whether the error also happened before that window.",
            ])."\nThen report a table: the error, the count, first seen, last seen, and a one-line guess at the cause. Say which groups started inside the window.",
        );
    }
}
