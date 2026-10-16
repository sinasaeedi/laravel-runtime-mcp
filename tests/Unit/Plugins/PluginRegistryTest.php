<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use SinaSaeedi\RuntimeMcp\Plugins\Contracts\Plugin;
use SinaSaeedi\RuntimeMcp\Plugins\PluginRegistry;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Plugins\FakeBasePlugin;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Plugins\FakeInstalledPlugin;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Plugins\FakeOptionalPlugin;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Plugins\FakeSecondPlugin;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\FakePingTool;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\SecondPingTool;

/**
 * A registry over the four test plugins, with runtime-mcp.plugins set to $settings.
 * Pest test-file functions are global, so the names carry the file's subject.
 *
 * @param  list<class-string<Plugin>>  $plugins
 */
function registryWithSettings(mixed $settings, array $plugins = [
    FakeBasePlugin::class, FakeSecondPlugin::class, FakeOptionalPlugin::class, FakeInstalledPlugin::class,
]): PluginRegistry
{
    $config = app(Repository::class);
    $config->set('runtime-mcp.plugins', $settings);

    return new PluginRegistry(app(), $config, $plugins);
}

/**
 * @param  list<Plugin>  $plugins
 * @return list<string>
 */
function registryPluginNames(array $plugins): array
{
    return array_map(fn (Plugin $plugin): string => $plugin->name(), $plugins);
}

it('turns on only the plugins that are on by default when no key is set', function () {
    $registry = registryWithSettings([]);

    expect(registryPluginNames($registry->active()))->toBe(['fake_base', 'fake_second'])
        ->and($registry->enabledButMissing())->toBe([])
        ->and($registry->tools())->toBe([FakePingTool::class, SecondPingTool::class]);
});

it('turns a plugin that is on by default off with an explicit false', function () {
    expect(registryPluginNames(registryWithSettings(['fake_base' => false])->active()))->toBe(['fake_second']);
});

it('turns a plugin on only for the value true, never for a truthy value', function (mixed $value) {
    expect(registryPluginNames(registryWithSettings(['fake_base' => $value])->active()))->toBe(['fake_second']);
})->with([
    'string true' => 'true',
    'string 1' => '1',
    'integer 1' => 1,
    'yes' => 'yes',
    'null' => null,
    'empty array' => [[]],
]);

it('turns an optional plugin on with true when its package is installed', function () {
    $registry = registryWithSettings(['fake_installed' => true]);

    expect(registryPluginNames($registry->active()))->toBe(['fake_base', 'fake_second', 'fake_installed'])
        ->and($registry->enabledButMissing())->toBe([]);
});

it('never activates an enabled plugin whose package is missing, and lists it', function () {
    $registry = registryWithSettings(['fake_optional' => true]);

    expect(registryPluginNames($registry->active()))->toBe(['fake_base', 'fake_second'])
        ->and(registryPluginNames($registry->enabledButMissing()))->toBe(['fake_optional'])
        ->and($registry->tools())->toBe([FakePingTool::class, SecondPingTool::class]);
});

it('turns every plugin off when the plugins value is not an array', function (mixed $value) {
    $registry = registryWithSettings($value);

    expect($registry->active())->toBe([])
        ->and($registry->enabledButMissing())->toBe([])
        ->and($registry->unknownKeys())->toBe([])
        ->and($registry->tools())->toBe([]);
})->with([
    'null' => null,
    'true' => true,
    'string' => 'all',
    'integer' => 1,
]);

it('reports a key that no plugin owns, and registers nothing for it', function () {
    $registry = registryWithSettings(['horizon' => true, 'fake_base' => true]);

    expect($registry->unknownKeys())->toBe(['horizon'])
        ->and($registry->tools())->toBe([FakePingTool::class, SecondPingTool::class]);
});

it('reports the keys of a list-shaped plugins value as strings', function () {
    $registry = registryWithSettings(['fake_base']);

    expect($registry->unknownKeys())->toBe(['0'])
        ->and(registryPluginNames($registry->active()))->toBe(['fake_base', 'fake_second']);
});

it('lists tools in registry order', function () {
    expect(registryWithSettings([], [FakeSecondPlugin::class, FakeBasePlugin::class])->tools())
        ->toBe([SecondPingTool::class, FakePingTool::class]);
});

it('asks Composer whether a plugin package is installed', function () {
    $registry = registryWithSettings([]);

    expect($registry->isInstalled(new FakeBasePlugin))->toBeTrue()
        ->and($registry->isInstalled(new FakeInstalledPlugin))->toBeTrue()
        ->and($registry->isInstalled(new FakeOptionalPlugin))->toBeFalse();
});

it('reads the config on every call, never once', function () {
    $registry = registryWithSettings([]);
    expect(registryPluginNames($registry->active()))->toBe(['fake_base', 'fake_second']);

    app(Repository::class)->set('runtime-mcp.plugins', ['fake_second' => false]);

    expect(registryPluginNames($registry->active()))->toBe(['fake_base']);
});

it('builds each plugin through the container and refuses a non-plugin', function () {
    app()->bind(FakeBasePlugin::class, fn (): stdClass => new stdClass);

    registryWithSettings([])->all();
})->throws(LogicException::class, 'does not implement the Plugin contract');

it('is a scoped binding with the five base plugins', function () {
    $first = app(PluginRegistry::class);

    expect(app(PluginRegistry::class))->toBe($first)
        ->and(registryPluginNames($first->all()))->toBe(['core', 'models', 'database', 'queue', 'logs']);

    app()->forgetScopedInstances();

    expect(app(PluginRegistry::class))->not->toBe($first);
});
