<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use SinaSaeedi\RuntimeMcp\Plugins\Logs\Prompts\TriageRecentErrors;
use SinaSaeedi\RuntimeMcp\Plugins\PluginRegistry;
use SinaSaeedi\RuntimeMcp\Prompts\Contracts\RuntimePrompt;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootGuard;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootIssue;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootState;
use SinaSaeedi\RuntimeMcp\Security\Boot\Rules\PromptClasses;
use SinaSaeedi\RuntimeMcp\Security\Boot\Rules\PromptNames;
use SinaSaeedi\RuntimeMcp\Security\Boot\Rules\PromptsUnique;
use SinaSaeedi\RuntimeMcp\Security\Boot\Rules\PromptTools;
use SinaSaeedi\RuntimeMcp\Security\Boot\RuntimeMcpMisconfigured;
use SinaSaeedi\RuntimeMcp\Security\BootValidator;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Prompts\AbstractFixturePrompt;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Prompts\BadNamePrompt;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Prompts\DuplicatePrompt;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Prompts\EchoPrompt;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Prompts\NoToolPrompt;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Prompts\ThrowingConstructorPrompt;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Prompts\ThrowingDescriptionPrompt;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Prompts\ThrowingTitlePrompt;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Prompts\UnknownToolPrompt;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\EchoTool;
use SinaSaeedi\RuntimeMcp\Tests\Support\BootCapture;

/*
 * 01-36 Part F, spec 5.11: the prompts.* boot rules. They read config and build prompt classes through
 * the container; a bad entry turns the endpoint off in a strict environment and throws in a relaxed one.
 */

/** @return list<array{string, string, string}> rule id, severity, message */
function promptRulesFlat(BootIssue ...$issues): array
{
    return array_values(array_map(fn (BootIssue $issue): array => [$issue->ruleId, $issue->severity->value, $issue->message], $issues));
}

/** @return list<string> "rule:severity" of the whole validator, prompt rules only */
function promptRulesIssues(): array
{
    return array_values(array_filter(
        array_map(fn (BootIssue $issue): string => $issue->ruleId.':'.$issue->severity->value, app(BootValidator::class)->validate()->issues),
        fn (string $issue): bool => str_starts_with($issue, 'prompts.'),
    ));
}

it('PR1 passes the built-in prompts and an empty or missing custom list', function (mixed $prompts) {
    config(['runtime-mcp.prompts' => $prompts]);

    expect(promptRulesIssues())->toBe([]);
})->with(['empty' => [[]], 'missing' => [null]]);

it('PR2 fails prompts.class for a list that is not a list of class names', function (mixed $prompts) {
    config(['runtime-mcp.prompts' => $prompts]);

    expect(promptRulesFlat(...app(PromptClasses::class)->check()))->toBe([['prompts.class', 'error', PromptClasses::NOT_A_LIST]]);
})->with(['a string' => ['App\Prompts\Mine'], 'a map' => [['mine' => EchoPrompt::class]], 'true' => [true]]);

it('PR3 fails prompts.class for each entry that is not a concrete RuntimePrompt, by position only', function () {
    config(['runtime-mcp.prompts' => [EchoPrompt::class, 'App\Nope', 42, EchoTool::class, RuntimePrompt::class, 'rmcp_secret', AbstractFixturePrompt::class]]);

    expect(promptRulesFlat(...app(PromptClasses::class)->check()))->toBe([
        ['prompts.class', 'error', 'Entry 1 of runtime-mcp.prompts is not a class that extends RuntimePrompt.'],
        ['prompts.class', 'error', 'Entry 2 of runtime-mcp.prompts is not a class that extends RuntimePrompt.'],
        ['prompts.class', 'error', 'Entry 3 of runtime-mcp.prompts is not a class that extends RuntimePrompt.'],
        ['prompts.class', 'error', 'Entry 4 of runtime-mcp.prompts is not a class that extends RuntimePrompt.'],
        ['prompts.class', 'error', 'Entry 5 of runtime-mcp.prompts is not a class that extends RuntimePrompt.'],
        ['prompts.class', 'error', 'Entry 6 of runtime-mcp.prompts is not a class that extends RuntimePrompt.'],
    ]);
});

it('PR4 fails prompts.name for a name that is not lower snake case', function () {
    config(['runtime-mcp.prompts' => [BadNamePrompt::class]]);

    expect(promptRulesFlat(...app(PromptNames::class)->check()))->toBe([
        ['prompts.name', 'error', 'Prompt `'.BadNamePrompt::class.'` has a name that is not lower snake case of at most 64 characters.'],
    ]);
});

it('PR5 fails prompts.unique for a custom prompt with a built-in name, and for a class listed twice', function () {
    config(['runtime-mcp.prompts' => [DuplicatePrompt::class, EchoPrompt::class, EchoPrompt::class]]);

    expect(promptRulesFlat(...app(PromptsUnique::class)->check()))->toBe([
        ['prompts.unique', 'error', 'Two prompts are named `health_check`: `SinaSaeedi\RuntimeMcp\Plugins\Core\Prompts\HealthCheck` and `'.DuplicatePrompt::class.'`.'],
        ['prompts.unique', 'error', 'Two prompts are named `echo_prompt`: `'.EchoPrompt::class.'` and `'.EchoPrompt::class.'`.'],
    ]);
});

it('PR6 warns prompts.tools for a custom prompt that names no tool or a tool that is not registered', function () {
    config(['runtime-mcp.prompts' => [NoToolPrompt::class, UnknownToolPrompt::class]]);

    expect(promptRulesFlat(...app(PromptTools::class)->check()))->toBe([
        ['prompts.tools', 'warning', 'Prompt `no_tool_prompt` names no tool, so it never shows.'],
        ['prompts.tools', 'warning', 'Prompt `unknown_tool_prompt` needs `echo`, which is not registered, so the prompt never shows.'],
        ['prompts.tools', 'warning', 'Prompt `unknown_tool_prompt` needs `nope_tool`, which is not registered, so the prompt never shows.'],
    ]);
});

it('PR7 never warns for a built-in prompt whose tools are off with their plugin', function () {
    config(['runtime-mcp.plugins.queue' => false, 'runtime-mcp.plugins.logs' => false]);

    expect(promptRulesIssues())->toBe([]);
});

it('PR8 reports a prompt that cannot be built as a rule that could not run', function () {
    config(['runtime-mcp.prompts' => [ThrowingConstructorPrompt::class]]);

    expect(promptRulesIssues())->toBe(['prompts.name:error', 'prompts.unique:error', 'prompts.tools:error']);
});

it('PR9 keeps the endpoint off in a strict environment, with one critical line, and never throws', function () {
    // BadNamePrompt needs the fixture tool `echo`, which no base plugin registers: prompts.tools warns too.
    $boot = BootCapture::boot(['runtime-mcp.enabled' => true, 'runtime-mcp.prompts' => [BadNamePrompt::class]], 'production');

    expect($boot->thrown)->toBeNull()
        ->and(Route::has('runtime-mcp'))->toBeFalse()
        ->and(Mcp::getLocalServer('runtime-mcp'))->toBeNull()
        ->and(app(BootState::class)->report()?->passed())->toBeFalse()
        ->and($boot->criticals())->toBe([BootGuard::CRITICAL])
        ->and(array_map(fn (BootIssue $issue): string => $issue->ruleId, $boot->events[0]->issues ?? []))->toBe(['prompts.name', 'prompts.tools']);
});

it('PR10 throws at boot in a relaxed environment', function () {
    $boot = BootCapture::boot(['runtime-mcp.enabled' => true, 'runtime-mcp.prompts' => ['App\Nope']], 'local');

    expect($boot->thrown)->toBeInstanceOf(RuntimeMcpMisconfigured::class)
        ->and(app(BootState::class)->report()?->passed())->toBeFalse();
});

it('PR11 keeps the endpoint on with a prompts.tools warning only', function () {
    $boot = BootCapture::boot(['runtime-mcp.enabled' => true, 'runtime-mcp.prompts' => [NoToolPrompt::class]], 'production');

    expect($boot->thrown)->toBeNull()
        ->and(Route::has('runtime-mcp'))->toBeTrue()
        ->and($boot->warnings())->toHaveCount(1);
});

it('PR12 takes the prompts of a plugin that is off away, so a custom prompt may reuse such a name', function () {
    config(['runtime-mcp.plugins.core' => false, 'runtime-mcp.plugins.logs' => false, 'runtime-mcp.prompts' => [DuplicatePrompt::class]]);

    expect(app(PluginRegistry::class)->prompts())->not->toContain(TriageRecentErrors::class)
        ->and(app(PluginRegistry::class)->prompts())->toHaveCount(2)
        ->and(app(PromptsUnique::class)->check())->toBe([]);
});

it('PR13 reports a prompt whose title() or description() throws as a rule that could not run, so no request builds it', function (string $class) {
    // McpPromptAdapter reads both in its constructor, outside any try: without this boot error every MCP
    // request would fail with HTTP 500 while boot validation and doctor pass (01-36 triage, m1).
    config(['runtime-mcp.prompts' => [$class]]);

    expect(promptRulesIssues())->toBe(['prompts.name:error', 'prompts.unique:error', 'prompts.tools:error']);
})->with([ThrowingTitlePrompt::class, ThrowingDescriptionPrompt::class]);
