<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Plugins\Contracts\Plugin;
use SinaSaeedi\RuntimeMcp\Plugins\Core\CorePlugin;
use SinaSaeedi\RuntimeMcp\Plugins\Database\DatabasePlugin;
use SinaSaeedi\RuntimeMcp\Plugins\Logs\LogsPlugin;
use SinaSaeedi\RuntimeMcp\Plugins\Models\ModelsPlugin;
use SinaSaeedi\RuntimeMcp\Plugins\PluginRegistry;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\QueuePlugin;

// Each tool story (01-18 to 01-26) updates the expected tools() list of its plugin here.
dataset('base plugins', [
    'core' => [CorePlugin::class, 'core', 'app_', false, []],
    'models' => [ModelsPlugin::class, 'models', 'model_', true, []],
    'database' => [DatabasePlugin::class, 'database', 'db_', true, []],
    'queue' => [QueuePlugin::class, 'queue', 'queue_', false, []],
    'logs' => [LogsPlugin::class, 'logs', 'logs_', false, []],
]);

it('knows the five base plugins, in this order', function () {
    expect(PluginRegistry::PLUGINS)->toBe([
        CorePlugin::class, ModelsPlugin::class, DatabasePlugin::class, QueuePlugin::class, LogsPlugin::class,
    ]);
});

/**
 * @param  class-string<Plugin>  $class
 * @param  list<class-string>  $tools
 */
it('describes a base plugin as spec 4.2 and Part B say', function (string $class, string $name, string $prefix, bool $readsDatabase, array $tools) {
    $plugin = app($class);
    assert($plugin instanceof Plugin);

    expect($plugin->name())->toBe($name)
        ->and($plugin->toolPrefix())->toBe($prefix)
        ->and($plugin->enabledByDefault())->toBeTrue()
        ->and($plugin->requiredPackage())->toBeNull()
        ->and($plugin->requiresReadOnlyConnection())->toBe($readsDatabase)
        ->and($plugin->description())->toMatch('/^[A-Z].{10,}\.$/')
        ->and($plugin->tools())->toBe($tools)
        ->and($plugin->bootRules())->toBe([])
        ->and($plugin->prompts())->toBe([]);
})->with('base plugins');

it('uses snake case names and prefixes that end with an underscore', function () {
    foreach (app(PluginRegistry::class)->all() as $plugin) {
        expect($plugin->name())->toMatch('/^[a-z][a-z0-9_]*$/')
            ->and($plugin->toolPrefix())->toMatch('/^[a-z][a-z0-9]*_$/');
    }
});

it('has one config key per base plugin, all on, in registry order', function () {
    $names = array_map(fn (Plugin $plugin): string => $plugin->name(), app(PluginRegistry::class)->all());

    expect(config('runtime-mcp.plugins'))->toBe(array_fill_keys($names, true));
});

it('turns on all five base plugins with the package config', function () {
    $registry = app(PluginRegistry::class);

    expect(array_map(fn (Plugin $plugin): string => $plugin->name(), $registry->active()))
        ->toBe(['core', 'models', 'database', 'queue', 'logs'])
        ->and($registry->enabledButMissing())->toBe([])
        ->and($registry->unknownKeys())->toBe([]);
});
