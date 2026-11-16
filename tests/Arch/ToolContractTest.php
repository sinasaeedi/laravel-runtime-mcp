<?php

declare(strict_types=1);

use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Tool;
use SinaSaeedi\RuntimeMcp\Mcp\McpPromptAdapter;
use SinaSaeedi\RuntimeMcp\Mcp\McpToolAdapter;
use SinaSaeedi\RuntimeMcp\Prompts\Contracts\RuntimePrompt;
use SinaSaeedi\RuntimeMcp\Tests\Support\SourceFiles;
use SinaSaeedi\RuntimeMcp\Tools\Contracts\RuntimeTool;

/*
 * Part G: no tool can reach laravel/mcp around the pipeline. Only McpToolAdapter is a laravel/mcp
 * Tool, and every tool in a plugin is a final RuntimeTool. The import rule (only src/Mcp/ uses
 * Laravel\Mcp) is LaravelMcpIsolationTest.
 */

it('has McpToolAdapter as the only laravel/mcp Tool in src/', function () {
    $tools = array_values(array_filter(
        SourceFiles::classNames(),
        fn (string $class): bool => class_exists($class) && is_subclass_of($class, Tool::class),
    ));

    expect($tools)->toBe([McpToolAdapter::class])
        ->and((new ReflectionClass(McpToolAdapter::class))->isFinal())->toBeTrue();
});

it('makes every class under a plugin Tools namespace a final RuntimeTool', function () {
    $offenders = [];

    foreach (SourceFiles::classNames() as $class) {
        if (preg_match('/^SinaSaeedi\\\\RuntimeMcp\\\\Plugins\\\\[A-Za-z]+\\\\Tools\\\\/', $class) !== 1) {
            continue;
        }

        if (! is_subclass_of($class, RuntimeTool::class) || ! (new ReflectionClass($class))->isFinal()) {
            $offenders[] = $class;
        }
    }

    expect($offenders)->toBe([]);
});

it('keeps the ability rule out of reach of a tool', function () {
    expect((new ReflectionMethod(RuntimeTool::class, 'ability'))->isFinal())->toBeTrue();
});

// 01-36: the same two rules for prompts. laravel/mcp only ever sees McpPromptAdapter, and every prompt in
// a plugin is a final RuntimePrompt, so it passes PromptsHoldNoAppDataTest.
it('PC1 has McpPromptAdapter as the only laravel/mcp Prompt in src/', function () {
    $prompts = array_values(array_filter(
        SourceFiles::classNames(),
        fn (string $class): bool => class_exists($class) && is_subclass_of($class, Prompt::class),
    ));

    expect($prompts)->toBe([McpPromptAdapter::class])
        ->and((new ReflectionClass(McpPromptAdapter::class))->isFinal())->toBeTrue();
});

it('PC2 makes every class under a plugin Prompts namespace a final RuntimePrompt', function () {
    $offenders = [];
    $found = 0;

    foreach (SourceFiles::classNames() as $class) {
        if (preg_match('/^SinaSaeedi\\\\RuntimeMcp\\\\Plugins\\\\[A-Za-z]+\\\\Prompts\\\\/', $class) !== 1) {
            continue;
        }

        $found++;

        if (! is_subclass_of($class, RuntimePrompt::class) || ! (new ReflectionClass($class))->isFinal()) {
            $offenders[] = $class;
        }
    }

    expect($offenders)->toBe([])
        ->and($found)->toBe(4);
});
