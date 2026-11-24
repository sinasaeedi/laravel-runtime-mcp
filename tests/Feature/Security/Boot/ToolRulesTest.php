<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use SinaSaeedi\RuntimeMcp\Plugins\Core\Tools\AppInfo;
use SinaSaeedi\RuntimeMcp\Plugins\Database\Tools\DbQuery;
use SinaSaeedi\RuntimeMcp\Plugins\PluginRegistry;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootGuard;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootIssue;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootState;
use SinaSaeedi\RuntimeMcp\Security\Boot\Rules\ToolClasses;
use SinaSaeedi\RuntimeMcp\Security\Boot\Rules\ToolNames;
use SinaSaeedi\RuntimeMcp\Security\Boot\Rules\ToolsUnique;
use SinaSaeedi\RuntimeMcp\Security\Boot\RuntimeMcpMisconfigured;
use SinaSaeedi\RuntimeMcp\Security\BootValidator;
use SinaSaeedi\RuntimeMcp\Server\RuntimeServer;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Prompts\EchoPrompt;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Prompts\ErpOrderPrompt;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\Custom\BadNameTool;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\Custom\CustomFixtureTool;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\Custom\DbQueryNameTool;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\Custom\ErpOrderTool;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\Custom\ErpStatusTool;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\Custom\ThrowingConstructorTool;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\Custom\ThrowingDescriptionTool;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\Custom\ThrowingOpensWorldTool;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\Custom\ThrowingSchemaTool;
use SinaSaeedi\RuntimeMcp\Tests\Support\BootCapture;
use SinaSaeedi\RuntimeMcp\Tools\Contracts\RuntimeTool;

/*
 * 01-29 Part A, spec 5.11: the tools.* boot rules. They read config and build the custom tool classes through the
 * container; a bad entry turns the endpoint off in a strict environment and throws in a relaxed one. The harness
 * environment is `testing` (relaxed) with the base plugins, so validate() and the rules are called directly.
 */

/** @return list<array{string, string, string}> rule id, severity, message */
function toolRulesFlat(BootIssue ...$issues): array
{
    return array_values(array_map(fn (BootIssue $issue): array => [$issue->ruleId, $issue->severity->value, $issue->message], $issues));
}

/**
 * @param  list<string>  $prefixes
 * @return list<string> "rule:severity" of the whole validator, for the rules whose id starts with a prefix
 */
function toolRulesIssues(array $prefixes = ['tools.']): array
{
    $issues = array_map(fn (BootIssue $issue): string => $issue->ruleId.':'.$issue->severity->value, app(BootValidator::class)->validate()->issues);

    return array_values(array_filter($issues, function (string $issue) use ($prefixes): bool {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($issue, $prefix)) {
                return true;
            }
        }

        return false;
    }));
}

/** @return list<string> the classes that RuntimeServer registers after the active plugins' tools */
function toolRulesRegisteredCustom(): array
{
    $plugins = app(PluginRegistry::class)->tools();

    return array_slice(app(RuntimeServer::class)->tools(), count($plugins));
}

it('TR1 passes the built-in tools with an empty or missing custom list, and registers no custom tool', function (mixed $tools) {
    config(['runtime-mcp.tools' => $tools]);

    expect(toolRulesIssues())->toBe([])
        ->and(app(RuntimeServer::class)->tools())->toBe(app(PluginRegistry::class)->tools());
})->with(['empty' => [[]], 'missing' => [null]]);

it('TR2 fails tools.class for a value that is not a list of class names, and registers no custom tool', function (mixed $tools) {
    config(['runtime-mcp.tools' => $tools]);

    expect(toolRulesFlat(...app(ToolClasses::class)->check()))->toBe([['tools.class', 'error', ToolClasses::NOT_A_LIST]])
        ->and(toolRulesRegisteredCustom())->toBe([]);
})->with(['a string' => [ErpOrderTool::class], 'a map' => [['erp' => ErpOrderTool::class]], 'true' => [true]]);

it('TR3 fails tools.class for each entry that is not a concrete RuntimeTool, by position only, and leaves it out', function () {
    config(['runtime-mcp.tools' => [ErpOrderTool::class, 'App\Nope', 42, EchoPrompt::class, RuntimeTool::class, 'rmcp_secret', CustomFixtureTool::class, null]]);

    $issues = toolRulesFlat(...app(ToolClasses::class)->check());

    expect($issues)->toBe([
        ['tools.class', 'error', 'Entry 1 of runtime-mcp.tools is not a class that extends RuntimeTool.'],
        ['tools.class', 'error', 'Entry 2 of runtime-mcp.tools is not a class that extends RuntimeTool.'],
        ['tools.class', 'error', 'Entry 3 of runtime-mcp.tools is not a class that extends RuntimeTool.'],
        ['tools.class', 'error', 'Entry 4 of runtime-mcp.tools is not a class that extends RuntimeTool.'],
        ['tools.class', 'error', 'Entry 5 of runtime-mcp.tools is not a class that extends RuntimeTool.'],
        ['tools.class', 'error', 'Entry 6 of runtime-mcp.tools is not a class that extends RuntimeTool.'],
        ['tools.class', 'error', 'Entry 7 of runtime-mcp.tools is not a class that extends RuntimeTool.'],
    ])
        ->and(str_contains(json_encode($issues, JSON_THROW_ON_ERROR), 'rmcp_secret'))->toBeFalse()
        ->and(toolRulesRegisteredCustom())->toBe([ErpOrderTool::class]);
});

it('TR4 fails tools.class for a tool of this package, in any case and with a leading backslash, also with its plugin off', function (string $class, array $plugins) {
    foreach ($plugins as $plugin => $on) {
        config(["runtime-mcp.plugins.{$plugin}" => $on]);
    }

    config(['runtime-mcp.tools' => [ErpOrderTool::class, $class]]);

    expect(toolRulesFlat(...app(ToolClasses::class)->check()))->toBe([
        ['tools.class', 'error', 'Entry 1 of runtime-mcp.tools is a tool of this package. Turn its plugin on in runtime-mcp.plugins instead.'],
    ])
        ->and(toolRulesRegisteredCustom())->toBe([ErpOrderTool::class])
        ->and(app(ToolsUnique::class)->check())->toBe([]);
})->with([
    'db_query, plugin on' => [DbQuery::class, []],
    'db_query, plugin off' => [DbQuery::class, ['database' => false]],
    'lower case' => [strtolower(DbQuery::class), ['database' => false]],
    'a leading backslash' => ['\\'.AppInfo::class, ['core' => false]],
]);

it('TR5 fails tools.name for a custom name that is not lower snake case, also one that ends with a newline', function () {
    config(['runtime-mcp.tools' => [ErpOrderTool::class, BadNameTool::class]]);

    expect(toolRulesFlat(...app(ToolNames::class)->check()))->toBe([
        ['tools.name', 'error', 'Tool `'.BadNameTool::class.'` has a name that is not lower snake case of at most 64 characters.'],
    ])
        ->and(toolRulesIssues())->toBe(['tools.name:error']);
});

it('TR6 fails tools.unique for a custom tool with a built-in name, and for a class listed two times', function () {
    config(['runtime-mcp.tools' => [DbQueryNameTool::class, ErpOrderTool::class, ErpOrderTool::class]]);

    expect(toolRulesFlat(...app(ToolsUnique::class)->check()))->toBe([
        ['tools.unique', 'error', 'Two tools are named `db_query`: `'.DbQuery::class.'` and `'.DbQueryNameTool::class.'`.'],
        ['tools.unique', 'error', 'Two tools are named `erp_order`: `'.ErpOrderTool::class.'` and `'.ErpOrderTool::class.'`.'],
    ]);
});

it('TR7 never prints a duplicate name that is not valid', function () {
    config(['runtime-mcp.tools' => [BadNameTool::class, BadNameTool::class]]);

    expect(toolRulesFlat(...app(ToolsUnique::class)->check()))->toBe([
        ['tools.unique', 'error', 'Two tools are named `(not a valid name)`: `'.BadNameTool::class.'` and `'.BadNameTool::class.'`.'],
    ]);
});

it('TR8 frees a built-in name when its plugin is off, so a custom tool may use it', function () {
    config(['runtime-mcp.plugins.database' => false, 'runtime-mcp.tools' => [DbQueryNameTool::class]]);

    expect(toolRulesIssues())->toBe([])
        ->and(toolRulesRegisteredCustom())->toBe([DbQueryNameTool::class]);
});

it('TR9 reports a custom tool that cannot be built, or whose listed parts throw, as a rule that could not run, by class only', function (string $class) {
    // McpToolAdapter reads name() and description() in its constructor, and tools/list reads tier(), opensWorld()
    // and schema() through toArray(), outside any try: without this boot error every MCP request (or every
    // tools/list) would fail while boot validation and doctor pass (01-36 triage, m1).
    config(['runtime-mcp.tools' => [$class]]);

    $issues = app(BootValidator::class)->validate()->issues;
    $tools = array_values(array_filter($issues, fn (BootIssue $issue): bool => str_starts_with($issue->ruleId, 'tools.')));

    expect(toolRulesFlat(...$tools))->toBe([
        ['tools.name', 'error', 'Rule tools.name could not run: RuntimeException.'],
        ['tools.unique', 'error', 'Rule tools.unique could not run: RuntimeException.'],
        ['tools.reserved', 'error', 'Rule tools.reserved could not run: RuntimeException.'], // 01-40
    ])
        ->and(str_contains(json_encode(toolRulesFlat(...$issues), JSON_THROW_ON_ERROR), 'rmcp_secret'))->toBeFalse();
})->with([ThrowingConstructorTool::class, ThrowingDescriptionTool::class, ThrowingSchemaTool::class, ThrowingOpensWorldTool::class]);

/*
 * Spec 11.2 "every rule in section 5.11, in a strict and in a relaxed environment", DoD 4: each of the three rules
 * alone (01-29 triage of the review's Tests note: tools.unique went through neither boot path, tools.name not relaxed).
 */
const TOOL_RULES_BAD_ENTRIES = [
    'tools.class and tools.name' => [['App\Missing', BadNameTool::class], ['tools.class', 'tools.name']],
    'tools.class, a package tool' => [[DbQuery::class], ['tools.class']],
    'tools.name' => [[BadNameTool::class], ['tools.name']],
    'tools.unique' => [[ErpOrderTool::class, ErpOrderTool::class], ['tools.unique']],
];

it('TR10 keeps the endpoint off in a strict environment, with one critical line, and never throws', function (array $tools, array $rules) {
    $boot = BootCapture::boot(['runtime-mcp.enabled' => true, 'runtime-mcp.tools' => $tools], 'production');

    expect($boot->thrown)->toBeNull()
        ->and(Route::has('runtime-mcp'))->toBeFalse()
        ->and(Mcp::getLocalServer('runtime-mcp'))->toBeNull()
        ->and(app(BootState::class)->report()?->passed())->toBeFalse()
        ->and($boot->criticals())->toBe([BootGuard::CRITICAL])
        ->and(array_map(fn (BootIssue $issue): string => $issue->ruleId, $boot->events[0]->issues ?? []))->toBe($rules);
})->with(TOOL_RULES_BAD_ENTRIES);

it('TR11 throws at boot in a relaxed environment', function (array $tools, array $rules) {
    $boot = BootCapture::boot(['runtime-mcp.enabled' => true, 'runtime-mcp.tools' => $tools], 'local');
    $errors = app(BootState::class)->report()?->errors() ?? [];

    expect($boot->thrown)->toBeInstanceOf(RuntimeMcpMisconfigured::class)
        ->and(app(BootState::class)->report()?->passed())->toBeFalse()
        ->and(array_map(fn (BootIssue $issue): string => $issue->ruleId, $errors))->toBe($rules);
})->with(TOOL_RULES_BAD_ENTRIES);

it('TR12 registers the endpoint with valid custom tools in a strict environment', function () {
    $boot = BootCapture::boot(['runtime-mcp.enabled' => true, 'runtime-mcp.tools' => [ErpOrderTool::class, ErpStatusTool::class]], 'production');

    expect($boot->thrown)->toBeNull()
        ->and(Route::has('runtime-mcp'))->toBeTrue()
        ->and(app(BootState::class)->report()?->passed())->toBeTrue();
});

it('TR13 lets a custom prompt need a custom tool: prompts.tools sees the registered custom tools', function () {
    config(['runtime-mcp.prompts' => [ErpOrderPrompt::class]]);
    expect(toolRulesIssues(['tools.', 'prompts.']))->toBe(['prompts.tools:warning']);

    config(['runtime-mcp.tools' => [ErpOrderTool::class]]);
    expect(toolRulesIssues(['tools.', 'prompts.']))->toBe([]);
});

it('TR14 reports a missing custom class once, as tools.class: the prompt rules never build it', function () {
    config(['runtime-mcp.tools' => ['App\Missing'], 'runtime-mcp.prompts' => [ErpOrderPrompt::class]]);

    expect(toolRulesIssues(['tools.', 'prompts.']))->toBe(['tools.class:error', 'prompts.tools:warning']);
});

it('TR15 registers the custom tools after the active plugins\' tools, in config order', function () {
    config(['runtime-mcp.tools' => [ErpStatusTool::class, ErpOrderTool::class]]);

    $tools = app(RuntimeServer::class)->tools();

    expect(array_slice($tools, 0, -2))->toBe(app(PluginRegistry::class)->tools())
        ->and(array_slice($tools, -2))->toBe([ErpStatusTool::class, ErpOrderTool::class]);
});

it('TR16 registers the config file\'s example once its // is removed: the line is inside the tools list', function () {
    // 01-29 triage N1: an example line above `'tools' => []` became the unkeyed top-level key 0, and no rule saw it.
    $line = '// App\\RuntimeMcp\\Tools\\OrderStatusFromErp::class,';
    $source = (string) file_get_contents(dirname(__DIR__, 4).'/config/runtime-mcp.php');
    expect(substr_count($source, $line))->toBe(1);

    $copy = tempnam(sys_get_temp_dir(), 'rmcp-config-');
    assert(is_string($copy));
    file_put_contents($copy, str_replace($line, ltrim($line, '/ '), $source));

    try {
        $config = require $copy;
    } finally {
        unlink($copy);
    }

    assert(is_array($config));
    expect($config['tools'] ?? null)->toBe(['App\\RuntimeMcp\\Tools\\OrderStatusFromErp'])
        ->and(array_filter(array_keys($config), 'is_int'))->toBe([]);
});
