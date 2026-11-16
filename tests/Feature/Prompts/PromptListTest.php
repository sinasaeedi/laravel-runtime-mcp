<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Exceptions;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Models\Order;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Prompts\EchoPrompt;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Prompts\NoToolPrompt;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Prompts\ThrowingArgumentsPrompt;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Prompts\ThrowingToolsPrompt;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Prompts\UnknownToolPrompt;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Prompts\WrongArgumentPrompt;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\FixtureTools;
use SinaSaeedi\RuntimeMcp\Tests\Support\Prompts;
use SinaSaeedi\RuntimeMcp\Tests\Support\ProtocolEra;

/*
 * 01-36 Part B, spec 5.5, 5.14: prompts/list shows a prompt only when the Gate allows every tool it
 * needs. laravel/mcp's ListPrompts filters with McpPromptAdapter::shouldRegister().
 */

afterEach(fn () => AuditLog::clear());

it('PL1 lists the four built-in prompts with their arguments when the Gates allow their tools, in both eras', function (ProtocolEra $era) {
    Prompts::serve(['runtime-mcp.models' => ['order' => ['class' => Order::class]], 'runtime-mcp.logs.channels' => ['stack', 'daily']]);

    $prompts = currentTest()->mcpPost('prompts/list', era: $era)->assertOk()->json('result.prompts');

    expect($prompts)->toBe([
        [
            'name' => 'health_check',
            'title' => 'Health check',
            'description' => 'A short health check of this server: app state, queues, failed jobs and recent errors.',
            'arguments' => [],
        ],
        [
            'name' => 'explain_record',
            'title' => 'Explain a record',
            'description' => 'Read one record with its relations and explain it in plain words.',
            'arguments' => [
                ['name' => 'model', 'description' => 'The model alias, as runtime-mcp.models names it. One of: order.', 'required' => true],
                ['name' => 'key', 'description' => 'The primary key of the record: letters, digits, _ and -, at most 64 characters.', 'required' => true],
            ],
        ],
        [
            'name' => 'investigate_failed_jobs',
            'title' => 'Investigate failed jobs',
            'description' => 'Find which queue jobs fail and the most likely cause, with read-only tools.',
            'arguments' => [
                ['name' => 'since', 'description' => 'How far back to look: a number and h or d, for example 24h or 7d. Default 24h.', 'required' => false],
                ['name' => 'job_class', 'description' => 'Only this job class, for example App\Jobs\SendInvoice.', 'required' => false],
            ],
        ],
        [
            'name' => 'triage_recent_errors',
            'title' => 'Triage recent errors',
            'description' => 'Group the recent errors in the logs by cause, with counts and a likely cause for each.',
            'arguments' => [
                ['name' => 'since', 'description' => 'How far back to look: a number and h or d, for example 24h or 7d. Default 24h.', 'required' => false],
                ['name' => 'channel', 'description' => 'The log channel to read. Default: the first configured channel. One of: stack, daily.', 'required' => false],
            ],
        ],
    ]);
})->with([ProtocolEra::Legacy, ProtocolEra::Modern]);

it('PL2 hides a prompt when one of the tools it needs has no Gate, and keeps the others', function (string $tool, string $hidden) {
    Prompts::serve(allow: array_values(array_diff(Prompts::BUILT_IN_TOOLS, [$tool])));

    expect(Prompts::listed())->not->toContain($hidden)
        ->and(Prompts::listed())->toHaveCount(3);
})->with([
    ['queue_failed_job_show', 'investigate_failed_jobs'],
    ['queue_failed_jobs_summary', 'investigate_failed_jobs'],
    ['logs_search', 'triage_recent_errors'],
    ['logs_recent_errors', 'triage_recent_errors'],
    ['model_show', 'explain_record'],
    ['app_info', 'health_check'],
    ['queue_overview', 'health_check'],
]);

it('PL3 hides a prompt when a Gate denies one of its tools', function () {
    Prompts::serve();
    Illuminate\Support\Facades\Gate::define('runtime-mcp.model_show', fn (): bool => false);

    expect(Prompts::listed())->toBe(['health_check', 'investigate_failed_jobs', 'triage_recent_errors']);
});

it('PL4 lists nothing without a Gate (default deny)', function () {
    Prompts::serve(allow: []);

    expect(Prompts::listed())->toBe([]);
});

it('PL5 takes a plugin\'s prompts away when the plugin is off, and a prompt that needs its tools too', function (string $plugin, array $listed) {
    /** @var list<string> $listed */
    Prompts::serve(["runtime-mcp.plugins.{$plugin}" => false]);

    expect(Prompts::listed())->toBe($listed);
})->with([
    'logs' => ['logs', ['health_check', 'explain_record', 'investigate_failed_jobs']],
    'queue (health_check needs queue_overview)' => ['queue', ['explain_record', 'triage_recent_errors']],
    'models' => ['models', ['health_check', 'investigate_failed_jobs', 'triage_recent_errors']],
]);

it('PL6 lists a custom prompt after the built-in ones when the Gate allows its tools', function () {
    FixtureTools::serve();
    config(['runtime-mcp.prompts' => [EchoPrompt::class]]);

    expect(Prompts::listed())->toBe(['echo_prompt']);
});

it('PL7 never lists a custom prompt that names no tool or needs a tool that is not registered', function () {
    FixtureTools::serve();
    config(['runtime-mcp.prompts' => [NoToolPrompt::class, UnknownToolPrompt::class, EchoPrompt::class]]);

    expect(Prompts::listed())->toBe(['echo_prompt']);
});

it('PL8 hides a prompt whose own code throws, reports it once, and still answers the list, also in debug mode', function (string $class) {
    FixtureTools::serve(['app.debug' => true]);
    config(['runtime-mcp.prompts' => [$class, EchoPrompt::class]]);
    Exceptions::fake();

    expect(Prompts::listed())->toBe(['echo_prompt']);
    Exceptions::assertReportedCount(1);
})->with([ThrowingToolsPrompt::class, ThrowingArgumentsPrompt::class, WrongArgumentPrompt::class]);

it('PL9 says when an argument has no allowed value on this server', function () {
    Prompts::serve(['runtime-mcp.models' => []]);

    $prompts = currentTest()->mcpPost('prompts/list')->assertOk()->json('result.prompts');

    expect(data_get($prompts, '1.arguments.0.description'))->toBe('The model alias, as runtime-mcp.models names it. No value is configured on this server.');
});

it('PL10 lists only the valid model aliases and log channels, as the tools accept them', function () {
    Prompts::serve([
        'runtime-mcp.models' => ['order' => ['class' => Order::class], 'ghost' => ['class' => 'App\\Models\\Ghost']],
        'runtime-mcp.logs.channels' => ['stack', 'not a name!', 'stack', 'daily'],
    ]);

    $prompts = currentTest()->mcpPost('prompts/list')->assertOk()->json('result.prompts');

    expect(data_get($prompts, '1.arguments.0.description'))->toBe('The model alias, as runtime-mcp.models names it. One of: order.')
        ->and(data_get($prompts, '3.arguments.1.description'))->toBe('The log channel to read. Default: the first configured channel. One of: stack, daily.');
});
