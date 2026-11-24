<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\ExecuteTools;
use Laravel\Mcp\Server\Tools\SearchTools;
use SinaSaeedi\RuntimeMcp\Mcp\McpToolAdapter;
use SinaSaeedi\RuntimeMcp\Mcp\McpToolAdapters;
use SinaSaeedi\RuntimeMcp\Mcp\Wrapped\Rules\WrappedToolClasses;
use SinaSaeedi\RuntimeMcp\Mcp\Wrapped\Rules\WrappedToolDescriptions;
use SinaSaeedi\RuntimeMcp\Mcp\Wrapped\Rules\WrappedToolEntries;
use SinaSaeedi\RuntimeMcp\Mcp\Wrapped\Rules\WrappedToolNames;
use SinaSaeedi\RuntimeMcp\Mcp\Wrapped\Rules\WrappedToolOpenWorld;
use SinaSaeedi\RuntimeMcp\Mcp\Wrapped\Rules\WrappedToolRendersApp;
use SinaSaeedi\RuntimeMcp\Mcp\Wrapped\Rules\WrappedToolTiers;
use SinaSaeedi\RuntimeMcp\Mcp\Wrapped\WrappedTool;
use SinaSaeedi\RuntimeMcp\Mcp\Wrapped\WrappedTools;
use SinaSaeedi\RuntimeMcp\Plugins\Database\Tools\DbQuery;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootGuard;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootIssue;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootState;
use SinaSaeedi\RuntimeMcp\Security\Boot\Rules\ToolsUnique;
use SinaSaeedi\RuntimeMcp\Security\Boot\RuntimeMcpMisconfigured;
use SinaSaeedi\RuntimeMcp\Security\BootValidator;
use SinaSaeedi\RuntimeMcp\Server\RuntimeServer;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Mcp\AbstractLookupTool;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Mcp\BlankDescriptionTool;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Mcp\NoDescriptionTool;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Mcp\NoHandleTool;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Mcp\OrderLookupTool;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Mcp\ProtectedHandleTool;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Mcp\RendersAppChildTool;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Mcp\RendersAppTool;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Mcp\StubDescriptionTool;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Mcp\ThrowingConstructorMcpTool;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Mcp\ThrowingSchemaMcpTool;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Prompts\OrderLookupPrompt;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\Custom\ErpOrderTool;
use SinaSaeedi\RuntimeMcp\Tests\Support\BootCapture;

/*
 * 01-38 Part E, spec 5.11, 5.16: the wrapped_tools.* boot rules. They read config and class metadata; the description
 * warning and tools.unique build the valid entries. The harness environment is `testing` (relaxed) with the base
 * plugins, so validate() and the rules are called directly; WR12 and WR13 boot strict and relaxed.
 */

/**
 * @param  array<string, mixed>  $change
 * @return array<string, mixed> a valid entry of OrderLookupTool, with $change applied
 */
function wrappedRuleEntry(array $change = []): array
{
    return [...['class' => OrderLookupTool::class, 'name' => 'app_order_lookup', 'tier' => 'read_sensitive', 'open_world' => false], ...$change];
}

/** @return list<array{string, string, string}> rule id, severity, message */
function wrappedRulesFlat(BootIssue ...$issues): array
{
    return array_values(array_map(fn (BootIssue $issue): array => [$issue->ruleId, $issue->severity->value, $issue->message], $issues));
}

/**
 * @param  list<string>  $prefixes
 * @return list<string> "rule:severity" of the whole validator, for the rules whose id starts with a prefix
 */
function wrappedRulesIssues(array $prefixes = ['wrapped_tools.']): array
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

/** @return list<array{string, string}> [name, official class] of the tools the server registers from wrapped_tools */
function wrappedRulesBuilt(): array
{
    return array_map(fn (WrappedTool $tool): array => [$tool->name(), $tool->wrappedClass()], app(WrappedTools::class)->build());
}

it('WR1 passes with an empty or missing list, and builds no wrapped tool', function (mixed $wrapped) {
    config(['runtime-mcp.wrapped_tools' => $wrapped]);

    expect(wrappedRulesIssues())->toBe([])
        ->and(wrappedRulesBuilt())->toBe([]);
})->with(['empty' => [[]], 'missing' => [null]]);

it('WR2 fails wrapped_tools.entry for a value that is not a list, and builds nothing', function (mixed $wrapped) {
    config(['runtime-mcp.wrapped_tools' => $wrapped]);

    expect(wrappedRulesFlat(...app(WrappedToolEntries::class)->check()))->toBe([['wrapped_tools.entry', 'error', WrappedTools::NOT_A_LIST]])
        ->and(wrappedRulesIssues())->toBe(['wrapped_tools.entry:error'])
        ->and(wrappedRulesBuilt())->toBe([]);
})->with(['a string' => [OrderLookupTool::class], 'one entry, not in a list' => [wrappedRuleEntry()], 'true' => [true]]);

it('WR3 fails wrapped_tools.entry for an entry that is not an array of exactly the four keys, by position only, and leaves it out', function () {
    config(['runtime-mcp.wrapped_tools' => [
        wrappedRuleEntry(),
        OrderLookupTool::class,
        [OrderLookupTool::class, 'app_x', 'read', false],
        ['class' => OrderLookupTool::class, 'name' => 'app_x', 'tier' => 'read'],
        [...wrappedRuleEntry(['name' => 'app_y']), 'secret' => 'rmcp_secret'],
        null,
        ['klass' => OrderLookupTool::class, 'name' => 'app_z', 'tier' => 'read', 'open_world' => false],
    ]]);

    $issues = wrappedRulesFlat(...app(WrappedToolEntries::class)->check());

    expect($issues)->toBe([
        ['wrapped_tools.entry', 'error', sprintf(WrappedTools::BAD_ENTRY, 1)],
        ['wrapped_tools.entry', 'error', sprintf(WrappedTools::BAD_ENTRY, 2)],
        ['wrapped_tools.entry', 'error', sprintf(WrappedTools::BAD_ENTRY, 3)],
        ['wrapped_tools.entry', 'error', sprintf(WrappedTools::BAD_ENTRY, 4)],
        ['wrapped_tools.entry', 'error', sprintf(WrappedTools::BAD_ENTRY, 5)],
        ['wrapped_tools.entry', 'error', sprintf(WrappedTools::BAD_ENTRY, 6)],
    ])
        ->and(str_contains(json_encode($issues, JSON_THROW_ON_ERROR), 'rmcp_secret'))->toBeFalse()
        ->and(wrappedRulesIssues())->toBe(array_fill(0, 6, 'wrapped_tools.entry:error'))
        ->and(wrappedRulesBuilt())->toBe([['app_order_lookup', OrderLookupTool::class]]);
});

it('WR4 fails wrapped_tools.class for a class that is not a concrete laravel/mcp Tool with handle(), and leaves it out', function (mixed $class, string $message) {
    config(['runtime-mcp.wrapped_tools' => [wrappedRuleEntry(), wrappedRuleEntry(['class' => $class, 'name' => 'app_other'])]]);

    expect(wrappedRulesFlat(...app(WrappedToolClasses::class)->check()))->toBe([['wrapped_tools.class', 'error', sprintf($message, 1)]])
        ->and(wrappedRulesIssues())->toBe(['wrapped_tools.class:error'])
        ->and(wrappedRulesBuilt())->toBe([['app_order_lookup', OrderLookupTool::class]]);
})->with([
    'a missing class' => ['App\Mcp\Tools\Missing', WrappedTools::NOT_A_TOOL],
    'not a string' => [42, WrappedTools::NOT_A_TOOL],
    'a RuntimeTool' => [ErpOrderTool::class, WrappedTools::NOT_A_TOOL],
    'laravel/mcp\'s Tool itself' => [Tool::class, WrappedTools::NOT_A_TOOL],
    'an abstract tool' => [AbstractLookupTool::class, WrappedTools::NOT_A_TOOL],
    'no handle()' => [NoHandleTool::class, WrappedTools::NOT_A_TOOL],
    'a handle() that is not public' => [ProtectedHandleTool::class, WrappedTools::NOT_A_TOOL],
    'search_tools' => [SearchTools::class, WrappedTools::OWN_TOOL],
    'execute_tools' => [ExecuteTools::class, WrappedTools::OWN_TOOL],
    'our adapter' => [McpToolAdapter::class, WrappedTools::OWN_TOOL],
    'our adapter, other case, leading backslash' => ['\\'.strtoupper(McpToolAdapter::class), WrappedTools::OWN_TOOL],
]);

it('WR5 fails wrapped_tools.name for a name that is not lower snake case of at most 64 characters', function (mixed $name) {
    config(['runtime-mcp.wrapped_tools' => [wrappedRuleEntry(['name' => $name])]]);

    $issues = wrappedRulesFlat(...app(WrappedToolNames::class)->check());

    expect($issues)->toBe([['wrapped_tools.name', 'error', sprintf(WrappedTools::BAD_NAME, 0)]])
        ->and(wrappedRulesIssues())->toBe(['wrapped_tools.name:error'])
        ->and(wrappedRulesBuilt())->toBe([]);
})->with([
    'laravel/mcp\'s default' => ['order-lookup-tool'],
    'upper case' => ['App_order'],
    'a trailing newline' => ["app_order\n"],
    '65 characters' => [str_repeat('a', 65)],
    'not a string' => [7],
]);

it('WR6 fails wrapped_tools.tier for a tier that is not read or read_sensitive, with the v1.13.0 text for write_safe', function (mixed $tier, string $message) {
    config(['runtime-mcp.wrapped_tools' => [wrappedRuleEntry(['tier' => $tier])]]);

    expect(wrappedRulesFlat(...app(WrappedToolTiers::class)->check()))->toBe([['wrapped_tools.tier', 'error', sprintf($message, 0)]])
        ->and(wrappedRulesBuilt())->toBe([]);
})->with([
    'write_safe' => ['write_safe', WrappedTools::WRITE_TIER],
    'admin' => ['admin', WrappedTools::BAD_TIER],
    'upper case' => ['READ', WrappedTools::BAD_TIER],
    'null' => [null, WrappedTools::BAD_TIER],
]);

it('WR7 fails wrapped_tools.open_world for a value that is not a bool', function (mixed $openWorld) {
    config(['runtime-mcp.wrapped_tools' => [wrappedRuleEntry(['open_world' => $openWorld])]]);

    expect(wrappedRulesFlat(...app(WrappedToolOpenWorld::class)->check()))->toBe([['wrapped_tools.open_world', 'error', sprintf(WrappedTools::BAD_OPEN_WORLD, 0)]])
        ->and(wrappedRulesBuilt())->toBe([]);
})->with(['the text false' => ['false'], 'one' => [1], 'null' => [null]]);

it('WR8 fails wrapped_tools.renders_app for a class with #[RendersApp], also on a parent class', function (string $class) {
    config(['runtime-mcp.wrapped_tools' => [wrappedRuleEntry(['class' => $class])]]);

    expect(wrappedRulesFlat(...app(WrappedToolRendersApp::class)->check()))->toBe([['wrapped_tools.renders_app', 'error', sprintf(WrappedTools::RENDERS_APP_TOOL, 0)]])
        ->and(wrappedRulesIssues())->toBe(['wrapped_tools.renders_app:error'])
        ->and(wrappedRulesBuilt())->toBe([]);
})->with([RendersAppTool::class, RendersAppChildTool::class]);

it('WR9 reports each failed rule of one entry under its own id', function () {
    config(['runtime-mcp.wrapped_tools' => [['class' => 'App\Missing', 'name' => 'bad-name', 'tier' => 'write_safe', 'open_world' => 'no']]]);

    expect(wrappedRulesIssues())->toBe(['wrapped_tools.class:error', 'wrapped_tools.name:error', 'wrapped_tools.tier:error', 'wrapped_tools.open_world:error']);
});

it('WR10 warns, and only warns, for a wrapped tool with no real description', function (string $class) {
    config(['runtime-mcp.wrapped_tools' => [wrappedRuleEntry(['class' => $class])]]);

    expect(wrappedRulesFlat(...app(WrappedToolDescriptions::class)->check()))->toBe([
        ['wrapped_tools.description', 'warning', 'Wrapped tool `app_order_lookup` has no description; add #[Description] so the agent knows when to use it.'],
    ])
        ->and(wrappedRulesIssues(['tools.', 'wrapped_tools.']))->toBe(['wrapped_tools.description:warning']);
})->with([NoDescriptionTool::class, StubDescriptionTool::class, BlankDescriptionTool::class]);

it('WR11 gives no description warning for a tool with its own description', function () {
    config(['runtime-mcp.wrapped_tools' => [wrappedRuleEntry()]]);

    expect(app(WrappedToolDescriptions::class)->check())->toBe([]);
});

it('WR12 fails tools.unique for a wrapped name that a built-in or another wrapped tool has, naming the official classes', function () {
    config(['runtime-mcp.wrapped_tools' => [
        wrappedRuleEntry(['name' => 'db_query']),
        wrappedRuleEntry(),
        wrappedRuleEntry(['class' => NoDescriptionTool::class]),
    ]]);

    expect(wrappedRulesFlat(...app(ToolsUnique::class)->check()))->toBe([
        ['tools.unique', 'error', 'Two tools are named `db_query`: `'.DbQuery::class.'` and `'.OrderLookupTool::class.'`.'],
        ['tools.unique', 'error', 'Two tools are named `app_order_lookup`: `'.OrderLookupTool::class.'` and `'.NoDescriptionTool::class.'`.'],
    ]);
});

it('WR13 lets one class be wrapped two times under two names', function () {
    config(['runtime-mcp.wrapped_tools' => [wrappedRuleEntry(), wrappedRuleEntry(['name' => 'app_order_world', 'tier' => 'read', 'open_world' => true])]]);

    expect(wrappedRulesIssues(['tools.', 'wrapped_tools.']))->toBe([])
        ->and(wrappedRulesBuilt())->toBe([['app_order_lookup', OrderLookupTool::class], ['app_order_world', OrderLookupTool::class]]);
});

it('WR14 reports an official tool that cannot be built or listed as rules that could not run, by class only', function (string $class, array $expected) {
    // The adapter and its toArray() are built at boot (ToolInstances::wrapped()): tools/list reads schema() outside any
    // try, so without this error every tools/list would fail while boot validation passes.
    config(['runtime-mcp.wrapped_tools' => [wrappedRuleEntry(['class' => $class])]]);

    $issues = app(BootValidator::class)->validate()->issues;
    $ours = array_values(array_filter($issues, fn (BootIssue $issue): bool => str_starts_with($issue->ruleId, 'tools.') || str_starts_with($issue->ruleId, 'wrapped_tools.')));

    expect(wrappedRulesFlat(...$ours))->toBe($expected)
        ->and(str_contains(json_encode(wrappedRulesFlat(...$issues), JSON_THROW_ON_ERROR), 'rmcp_secret'))->toBeFalse();
})->with([
    'a throwing constructor' => [ThrowingConstructorMcpTool::class, [
        ['tools.unique', 'error', 'Rule tools.unique could not run: RuntimeException.'],
        ['tools.reserved', 'error', 'Rule tools.reserved could not run: RuntimeException.'], // 01-40
        ['wrapped_tools.description', 'error', 'Rule wrapped_tools.description could not run: RuntimeException.'],
    ]],
    'a throwing schema()' => [ThrowingSchemaMcpTool::class, [
        ['tools.unique', 'error', 'Rule tools.unique could not run: RuntimeException.'],
        ['tools.reserved', 'error', 'Rule tools.reserved could not run: RuntimeException.'], // 01-40
    ]],
]);

it('WR15 lets a custom prompt need a wrapped tool: prompts.tools sees the wrapped tools', function () {
    config(['runtime-mcp.prompts' => [OrderLookupPrompt::class]]);
    expect(wrappedRulesIssues(['prompts.']))->toBe(['prompts.tools:warning']);

    config(['runtime-mcp.wrapped_tools' => [wrappedRuleEntry()]]);
    expect(wrappedRulesIssues(['prompts.']))->toBe([]);
});

it('WR16 registers the wrapped tools after the plugin and custom tools, in config order', function () {
    config([
        'runtime-mcp.tools' => [ErpOrderTool::class],
        'runtime-mcp.wrapped_tools' => [wrappedRuleEntry(['name' => 'app_z']), wrappedRuleEntry(['name' => 'app_a'])],
    ]);

    $names = array_map(fn (McpToolAdapter $adapter): string => $adapter->name(), McpToolAdapters::fromDefinition(app(RuntimeServer::class), app()));

    expect(array_slice($names, -3))->toBe(['erp_order', 'app_z', 'app_a']);
});

/*
 * Spec 11.2 "every rule in section 5.11, in a strict and in a relaxed environment": each rule alone.
 */
const WRAPPED_RULES_BAD_ENTRIES = [
    'wrapped_tools.entry' => [[['class' => OrderLookupTool::class]], ['wrapped_tools.entry']],
    'wrapped_tools.class' => [[['class' => NoHandleTool::class, 'name' => 'app_x', 'tier' => 'read', 'open_world' => false]], ['wrapped_tools.class']],
    'wrapped_tools.name' => [[['class' => OrderLookupTool::class, 'name' => 'app-x', 'tier' => 'read', 'open_world' => false]], ['wrapped_tools.name']],
    'wrapped_tools.tier' => [[['class' => OrderLookupTool::class, 'name' => 'app_x', 'tier' => 'write_safe', 'open_world' => false]], ['wrapped_tools.tier']],
    'wrapped_tools.open_world' => [[['class' => OrderLookupTool::class, 'name' => 'app_x', 'tier' => 'read', 'open_world' => 'yes']], ['wrapped_tools.open_world']],
    'wrapped_tools.renders_app' => [[['class' => RendersAppTool::class, 'name' => 'app_x', 'tier' => 'read', 'open_world' => false]], ['wrapped_tools.renders_app']],
    'tools.unique' => [[['class' => OrderLookupTool::class, 'name' => 'db_query', 'tier' => 'read', 'open_world' => false]], ['tools.unique']],
];

it('WR17 keeps the endpoint off in a strict environment, with one critical line, and never throws', function (array $wrapped, array $rules) {
    $boot = BootCapture::boot(['runtime-mcp.enabled' => true, 'runtime-mcp.wrapped_tools' => $wrapped], 'production');

    expect($boot->thrown)->toBeNull()
        ->and(Route::has('runtime-mcp'))->toBeFalse()
        ->and(Mcp::getLocalServer('runtime-mcp'))->toBeNull()
        ->and(app(BootState::class)->report()?->passed())->toBeFalse()
        ->and($boot->criticals())->toBe([BootGuard::CRITICAL])
        ->and(array_map(fn (BootIssue $issue): string => $issue->ruleId, $boot->events[0]->issues ?? []))->toBe($rules);
})->with(WRAPPED_RULES_BAD_ENTRIES);

it('WR18 throws at boot in a relaxed environment', function (array $wrapped, array $rules) {
    $boot = BootCapture::boot(['runtime-mcp.enabled' => true, 'runtime-mcp.wrapped_tools' => $wrapped], 'local');
    $errors = app(BootState::class)->report()?->errors() ?? [];

    expect($boot->thrown)->toBeInstanceOf(RuntimeMcpMisconfigured::class)
        ->and(array_map(fn (BootIssue $issue): string => $issue->ruleId, $errors))->toBe($rules);
})->with(WRAPPED_RULES_BAD_ENTRIES);

it('WR19 registers the endpoint with a valid wrapped tool in a strict environment, and with only the description warning', function (string $class, array $warnings) {
    $boot = BootCapture::boot(['runtime-mcp.enabled' => true, 'runtime-mcp.wrapped_tools' => [wrappedRuleEntry(['class' => $class])]], 'production');

    expect($boot->thrown)->toBeNull()
        ->and(Route::has('runtime-mcp'))->toBeTrue()
        ->and(app(BootState::class)->report()?->passed())->toBeTrue()
        ->and(array_map(fn (BootIssue $issue): string => $issue->ruleId, app(BootState::class)->report()?->warnings() ?? []))->toBe($warnings);
})->with([
    'a described tool' => [OrderLookupTool::class, []],
    'no description' => [NoDescriptionTool::class, ['wrapped_tools.description']],
]);

it('WR20 registers the config file\'s example once its // is removed: the line is inside the wrapped_tools list', function () {
    $line = "// ['class' => App\\Mcp\\Tools\\OrderLookupTool::class, 'name' => 'app_order_lookup', 'tier' => 'read_sensitive', 'open_world' => false],";
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
    expect($config['wrapped_tools'] ?? null)->toBe([['class' => 'App\\Mcp\\Tools\\OrderLookupTool', 'name' => 'app_order_lookup', 'tier' => 'read_sensitive', 'open_world' => false]])
        ->and(array_filter(array_keys($config), 'is_int'))->toBe([]);
});
