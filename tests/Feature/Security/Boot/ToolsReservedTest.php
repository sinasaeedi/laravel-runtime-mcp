<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootGuard;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootIssue;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootState;
use SinaSaeedi\RuntimeMcp\Security\Boot\Rules\ToolsReserved;
use SinaSaeedi\RuntimeMcp\Security\Boot\RuntimeMcpMisconfigured;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Mcp\OrderLookupTool;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\Custom\ErpOrderTool;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\Custom\ExecuteToolsNameTool;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\Custom\SearchToolsNameTool;
use SinaSaeedi\RuntimeMcp\Tests\Support\BootCapture;

/*
 * 01-40 Part A, spec 5.11, 5.17, 10.1: no custom or wrapped tool may be named search_tools or execute_tools, whatever
 * the catalog setting. With the catalog on, ServerContext::tools() would throw "Duplicate server tool name" on every
 * tools/list.
 */

/** @return list<array{string, string, string}> rule id, severity, message */
function toolsReservedFlat(): array
{
    return array_map(
        fn (BootIssue $issue): array => [$issue->ruleId, $issue->severity->value, $issue->message],
        app(ToolsReserved::class)->check(),
    );
}

/** @return array<string, mixed> one wrapped_tools entry named $name */
function toolsReservedWrapped(string $name): array
{
    return ['class' => OrderLookupTool::class, 'name' => $name, 'tier' => 'read', 'open_world' => false];
}

it('TRS1 passes the built-in tools and a custom tool with another name', function (bool $catalog) {
    currentTest()->bootWith(['runtime-mcp.tools' => [ErpOrderTool::class], 'runtime-mcp.catalog.enabled' => $catalog]);

    expect(toolsReservedFlat())->toBe([]);
})->with(['catalog off' => [false], 'catalog on' => [true]]);

it('TRS2 fails a custom tool named search_tools or execute_tools, by class, whatever the catalog setting', function (bool $catalog) {
    currentTest()->bootWith([
        'runtime-mcp.tools' => [SearchToolsNameTool::class, ExecuteToolsNameTool::class],
        'runtime-mcp.catalog.enabled' => $catalog,
    ]);

    expect(toolsReservedFlat())->toBe([
        ['tools.reserved', 'error', 'Tool `'.SearchToolsNameTool::class.'` is named `search_tools`, which laravel/mcp\'s tool catalog reserves. Give it another name.'],
        ['tools.reserved', 'error', 'Tool `'.ExecuteToolsNameTool::class.'` is named `execute_tools`, which laravel/mcp\'s tool catalog reserves. Give it another name.'],
    ]);
})->with(['catalog off' => [false], 'catalog on' => [true]]);

it('TRS3 fails a wrapped tool given a reserved name, by its official class', function () {
    currentTest()->bootWith(['runtime-mcp.wrapped_tools' => [toolsReservedWrapped('execute_tools')]]);

    expect(toolsReservedFlat())->toBe([
        ['tools.reserved', 'error', 'Tool `'.OrderLookupTool::class.'` is named `execute_tools`, which laravel/mcp\'s tool catalog reserves. Give it another name.'],
    ]);
});

/* Spec 11.2 "every rule in section 5.11, in a strict and in a relaxed environment". */
const TOOLS_RESERVED_BAD = [
    'a custom tool' => [['runtime-mcp.tools' => [SearchToolsNameTool::class]]],
    'a wrapped tool' => [['runtime-mcp.wrapped_tools' => [['class' => OrderLookupTool::class, 'name' => 'search_tools', 'tier' => 'read', 'open_world' => false]]]],
];

it('TRS4 keeps the endpoint off in a strict environment, with one critical line, and never throws', function (array $config) {
    /** @var array<string, mixed> $config */
    $boot = BootCapture::boot(['runtime-mcp.enabled' => true, ...$config], 'production');

    expect($boot->thrown)->toBeNull()
        ->and(Route::has('runtime-mcp'))->toBeFalse()
        ->and(app(BootState::class)->report()?->passed())->toBeFalse()
        ->and($boot->criticals())->toBe([BootGuard::CRITICAL])
        ->and(array_map(fn (BootIssue $issue): string => $issue->ruleId, $boot->events[0]->issues ?? []))->toBe(['tools.reserved']);
})->with(TOOLS_RESERVED_BAD);

it('TRS5 throws at boot in a relaxed environment', function (array $config) {
    /** @var array<string, mixed> $config */
    $boot = BootCapture::boot(['runtime-mcp.enabled' => true, ...$config], 'local');
    $errors = app(BootState::class)->report()?->errors() ?? [];

    expect($boot->thrown)->toBeInstanceOf(RuntimeMcpMisconfigured::class)
        ->and(array_map(fn (BootIssue $issue): string => $issue->ruleId, $errors))->toBe(['tools.reserved']);
})->with(TOOLS_RESERVED_BAD);
