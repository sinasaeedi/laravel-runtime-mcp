<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Exceptions;
use SinaSaeedi\RuntimeMcp\Plugins\Contracts\Plugin;
use SinaSaeedi\RuntimeMcp\Plugins\PluginRegistry;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Plugins\FakeBasePlugin;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Plugins\FakeBrokenPlugin;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Plugins\FakeOptionalPlugin;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Plugins\FakeSecondPlugin;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\ProtocolEra;

// Since 01-08 every tools/call writes an audit entry.
beforeEach(fn () => AuditLog::clear());
afterEach(fn () => AuditLog::clear());

/**
 * Boots the endpoint with these plugins in the registry. laravel/mcp builds McpServer (and so
 * RuntimeServer and the registry) through the container per request, so the swap after the boot
 * is what the request sees.
 *
 * @param  list<class-string<Plugin>>  $plugins
 * @param  array<string, mixed>  $settings
 */
function serveWithPlugins(array $plugins, array $settings = []): void
{
    currentTest()->bootWith(['runtime-mcp.enabled' => true, 'runtime-mcp.plugins' => $settings]);

    app()->scoped(PluginRegistry::class, fn (Application $app): PluginRegistry => new PluginRegistry(
        $app, $app->make(Repository::class), $plugins,
    ));
}

/** @return list<string> */
function listedToolNames(ProtocolEra $era = ProtocolEra::Legacy): array
{
    $names = currentTest()->mcpPost('tools/list', era: $era)->assertOk()->json('result.tools.*.name');
    assert(is_array($names));

    return array_values(array_map(fn (mixed $name): string => is_string($name) ? $name : '', $names));
}

it('lists the tools of the active plugins, in registry order', function (ProtocolEra $era) {
    serveWithPlugins([FakeBasePlugin::class, FakeSecondPlugin::class]);

    expect(listedToolNames($era))->toBe(['fake_ping', 'second_ping']);
})->with([ProtocolEra::Legacy, ProtocolEra::Modern]);

it('does not list the tools of a plugin that is off', function () {
    serveWithPlugins([FakeBasePlugin::class, FakeSecondPlugin::class], ['fake_base' => false]);

    expect(listedToolNames())->toBe(['second_ping']);
});

it('registers each tool directly, never behind search_tools', function () {
    serveWithPlugins([FakeBasePlugin::class, FakeSecondPlugin::class]);

    $names = listedToolNames();

    expect(in_array('search_tools', $names, true))->toBeFalse()
        ->and(in_array('execute_tools', $names, true))->toBeFalse()
        ->and($names)->toBe(['fake_ping', 'second_ping']);
});

it('calls a registered tool directly', function (ProtocolEra $era) {
    serveWithPlugins([FakeBasePlugin::class]);

    currentTest()->mcpPost('tools/call', ['name' => 'fake_ping', 'arguments' => (object) []], era: $era)
        ->assertOk()
        ->assertJsonPath('result.isError', false)
        ->assertJsonPath('result.structuredContent.status', 'ok')
        ->assertJsonPath('result.structuredContent.data.reply', 'pong');
})->with([ProtocolEra::Legacy, ProtocolEra::Modern]);

it('neither lists nor calls the tool of an enabled plugin whose package is missing', function () {
    serveWithPlugins([FakeBasePlugin::class, FakeOptionalPlugin::class], ['fake_optional' => true]);

    expect(listedToolNames())->toBe(['fake_ping']);

    currentTest()->mcpPost('tools/call', ['name' => 'optional_ping', 'arguments' => (object) []])
        ->assertJsonPath('error.message', 'Tool [optional_ping] not found.');
});

it('follows a config change on the next request', function () {
    serveWithPlugins([FakeBasePlugin::class]);
    expect(listedToolNames())->toBe(['fake_ping']);

    app(Repository::class)->set('runtime-mcp.plugins', ['fake_base' => false]);

    expect(listedToolNames())->toBe([]);
});

it('fails the request, and lists nothing, when a plugin names a class that is not a tool', function () {
    serveWithPlugins([FakeBasePlugin::class, FakeBrokenPlugin::class]);
    Exceptions::fake();

    $response = currentTest()->mcpPost('tools/list');

    $response->assertStatus(500);
    expect($response->json('result'))->toBeNull();

    Exceptions::assertReported(fn (LogicException $e): bool => $e->getMessage() === '[stdClass] is not a RuntimeTool.');
});
