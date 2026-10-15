<?php

declare(strict_types=1);

// Spec rule 20: the package never connects to another MCP server, so no class, src/Mcp/ included,
// uses the laravel/mcp client. The prefix Laravel\Mcp\Client also covers Laravel\Mcp\Client\*.
// This cannot see Mcp::client() on the facade: never call it. 01-30 adds the runtime check.
arch('no package code uses the laravel/mcp client, src/Mcp/ included (rule 20)', function () {
    expect('SinaSaeedi\RuntimeMcp')
        ->not->toUse(['Laravel\Mcp\Client', 'Laravel\Mcp\WebClient']);
});

test('the laravel/mcp client classes exist', function () {
    expect(class_exists('Laravel\Mcp\Client'))->toBeTrue()
        ->and(class_exists('Laravel\Mcp\WebClient'))->toBeTrue()
        ->and(class_exists('Laravel\Mcp\Client\ClientManager'))->toBeTrue();
});
