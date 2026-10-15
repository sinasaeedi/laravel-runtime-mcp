<?php

declare(strict_types=1);

// Spec 4.1: when laravel/mcp changes its API, only src/Mcp/ changes.
// ignoring() is a plain prefix match, so the trailing backslash keeps a future
// SinaSaeedi\RuntimeMcp\McpSomething under the rule.
arch('only src/Mcp/ uses laravel/mcp (spec 4.1)', function () {
    expect('SinaSaeedi\RuntimeMcp')
        ->not->toUse('Laravel\Mcp')
        ->ignoring('SinaSaeedi\RuntimeMcp\Mcp\\');
});

test('the laravel/mcp namespace exists', function () {
    expect(class_exists('Laravel\Mcp\Server'))->toBeTrue();
});
