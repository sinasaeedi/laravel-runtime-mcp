<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Plugins\Contracts\Plugin;
use SinaSaeedi\RuntimeMcp\Plugins\Core\CorePlugin;
use SinaSaeedi\RuntimeMcp\Plugins\Core\Prompts\HealthCheck;
use SinaSaeedi\RuntimeMcp\Plugins\Logs\LogsPlugin;
use SinaSaeedi\RuntimeMcp\Plugins\Logs\Prompts\TriageRecentErrors;
use SinaSaeedi\RuntimeMcp\Plugins\Models\ModelsPlugin;
use SinaSaeedi\RuntimeMcp\Plugins\Models\Prompts\ExplainRecord;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\Prompts\InvestigateFailedJobs;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\QueuePlugin;
use SinaSaeedi\RuntimeMcp\Prompts\Contracts\RuntimePrompt;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Models\Order;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\Prompts;

/*
 * 01-36 Part E, spec 6.11: the full text of each built-in prompt. Each names the environment and the app
 * first, sends every tool only input it accepts (no `24h` to a tool: 01-23, 01-26), names an "if it is
 * available" tool only when the Gate allows it, and ends with the report shape and the rules paragraph.
 */

afterEach(fn () => AuditLog::clear());

const BUILT_IN_PROMPTS_TAIL = "\n\n".RuntimePrompt::REPORT."\n\n".RuntimePrompt::RULES;

it('PT1 writes investigate_failed_jobs for the default window, with the logs step when logs_search is allowed', function () {
    Prompts::serve(environment: 'prod-eu');

    expect(Prompts::text('investigate_failed_jobs'))->toBe(
        "Investigate the failed queue jobs on the `prod-eu` environment of `Runtime Test`. This server is read-only.\n\n"
        ."1. Call `queue_failed_jobs_summary` with no `from` and no `to` (the last 24 hours). Its `data.from` and `data.to` show the window the server used; use the same `from` and `to` in the next calls.\n"
        ."2. Take the job class with the most failures. Call `queue_failed_jobs_list` with that `job_class`, the same `from` and `to`, and `limit` 10.\n"
        ."3. Call `queue_failed_job_show` with the `id` of the newest failure, unchanged. Call it once more for an older failure if its exception differs.\n"
        ."4. Call `logs_search` with `level` `error`, `from` five minutes before and `to` five minutes after the newest `failed_at`.\n"
        .'Then report the failing job classes with their counts, and the most likely cause with its evidence.'
        .BUILT_IN_PROMPTS_TAIL.' Do not offer to retry or delete jobs.'
    );
});

it('PT2 writes investigate_failed_jobs for 7 days and one job class, without the logs step when logs_search is denied', function () {
    Prompts::serve(allow: array_values(array_diff(Prompts::BUILT_IN_TOOLS, ['logs_search'])));

    expect(Prompts::text('investigate_failed_jobs', ['since' => '7d', 'job_class' => 'App\Jobs\SendInvoice']))->toBe(
        "Investigate the failed queue jobs on the `testing` environment of `Runtime Test`. This server is read-only.\n\n"
        ."1. Call `queue_failed_jobs_summary` with `from` set to 7 days before now, as an ISO 8601 date-time. Its `data.from` and `data.to` show the window the server used; use the same `from` and `to` in the next calls.\n"
        ."2. Take the job class `App\Jobs\SendInvoice`. Call `queue_failed_jobs_list` with that `job_class`, the same `from` and `to`, and `limit` 10.\n"
        ."3. Call `queue_failed_job_show` with the `id` of the newest failure, unchanged. Call it once more for an older failure if its exception differs.\n"
        .'Then report the failing job classes with their counts, and the most likely cause with its evidence.'
        .BUILT_IN_PROMPTS_TAIL.' Do not offer to retry or delete jobs.'
    );
});

it('PT3 writes investigate_failed_jobs without the logs step when the logs plugin is off', function () {
    Prompts::serve(['runtime-mcp.plugins.logs' => false]);

    expect(Prompts::text('investigate_failed_jobs'))->not->toContain('logs_search')
        ->and(Prompts::text('investigate_failed_jobs'))->toContain("\n3. Call `queue_failed_job_show`");
});

it('PT4 writes triage_recent_errors for the default window and the default channel', function () {
    Prompts::serve();

    expect(Prompts::text('triage_recent_errors'))->toBe(
        "Triage the recent errors on the `testing` environment of `Runtime Test`. This server is read-only.\n\n"
        ."1. Call `logs_recent_errors` with no `since` (the last 24 hours). Its `data.since` shows the window the server used.\n"
        ."2. For each of the three largest groups, call `logs_search` with `contains` set to a short, exact part of `sample.message` (at most 200 characters), and no `from`, to see the full entries and whether the error also happened before that window.\n"
        .'Then report a table: the error, the count, first seen, last seen, and a one-line guess at the cause. Say which groups started inside the window.'
        .BUILT_IN_PROMPTS_TAIL
    );
});

it('PT5 writes triage_recent_errors for 1 hour on one channel', function () {
    Prompts::serve(['runtime-mcp.logs.channels' => ['stack', 'daily']]);

    expect(Prompts::text('triage_recent_errors', ['since' => '1h', 'channel' => 'daily']))->toBe(
        "Triage the recent errors on the `testing` environment of `Runtime Test`. This server is read-only.\n\n"
        ."1. Call `logs_recent_errors` with `since` set to 1 hour before now, as an ISO 8601 date-time and `channel` `daily`. Its `data.since` shows the window the server used.\n"
        ."2. For each of the three largest groups, call `logs_search` with `contains` set to a short, exact part of `sample.message` (at most 200 characters) and `channel` `daily`, and no `from`, to see the full entries and whether the error also happened before that window.\n"
        .'Then report a table: the error, the count, first seen, last seen, and a one-line guess at the cause. Say which groups started inside the window.'
        .BUILT_IN_PROMPTS_TAIL
    );
});

it('PT6 writes explain_record with the model and the key', function () {
    Prompts::serve(['runtime-mcp.models' => ['order' => ['class' => Order::class]]]);

    expect(Prompts::text('explain_record', ['model' => 'order', 'key' => 'A-1_x']))->toBe(
        "Explain the `order` record `A-1_x` on the `testing` environment of `Runtime Test`. This server is read-only.\n\n"
        .'Call `model_show` with `model` `order`, `key` `A-1_x`, and in `with` at most five of the relations that its description lists for `order`. Explain the record in plain words: its state, its important dates, and how its relations fit. Say which fields and relations you could not read.'
        .BUILT_IN_PROMPTS_TAIL
    );
});

it('PT7 writes health_check with every step when every tool is allowed', function () {
    Prompts::serve();

    expect(Prompts::text('health_check'))->toBe(
        "Give a short health check of the `testing` environment of `Runtime Test`. This server is read-only.\n\n"
        ."1. Call `app_info`. Note maintenance mode, the debug flag, and whether config and routes are cached.\n"
        ."2. Call `queue_overview`. Note any queue whose `status` is not `ok`, and any queue with a large `size`.\n"
        ."3. Call `queue_failed_jobs_summary` with no `from` and no `to` (the last 24 hours).\n"
        ."4. Call `logs_recent_errors` with `since` set to one hour before now, as an ISO 8601 date-time.\n"
        .'Then report one table with a status per area (ok, check, unknown) and one line of evidence each.'
        .BUILT_IN_PROMPTS_TAIL
    );
});

it('PT8 numbers the health_check steps without a gap when a middle tool is denied, and leaves out every denied one', function () {
    Prompts::serve(allow: ['app_info', 'queue_overview', 'logs_recent_errors']);
    $text = Prompts::text('health_check');

    expect($text)->not->toContain('queue_failed_jobs_summary')
        ->and($text)->toContain("\n3. Call `logs_recent_errors`")
        ->and($text)->not->toContain("\n4. ");

    Prompts::serve(allow: ['app_info', 'queue_overview']);

    expect(Prompts::text('health_check'))->not->toContain('logs_recent_errors')
        ->and(Prompts::text('health_check'))->not->toContain("\n3. ");
});

it('PT9 belongs to its plugin, and needs the tools of spec 6.11', function (string $pluginClass, string $promptClass, array $tools) {
    $plugin = app($pluginClass);
    $prompt = app($promptClass);
    assert($plugin instanceof Plugin && $prompt instanceof RuntimePrompt);

    expect($plugin->prompts())->toBe([$promptClass])
        ->and($prompt->tools())->toBe($tools);
})->with([
    [QueuePlugin::class, InvestigateFailedJobs::class, ['queue_failed_jobs_summary', 'queue_failed_jobs_list', 'queue_failed_job_show']],
    [LogsPlugin::class, TriageRecentErrors::class, ['logs_recent_errors', 'logs_search']],
    [ModelsPlugin::class, ExplainRecord::class, ['model_show']],
    [CorePlugin::class, HealthCheck::class, ['app_info', 'queue_overview']],
]);
