<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Plugins\AbstractPlugin;

it('gives a plugin the safe defaults: off, no package, nothing registered', function () {
    $plugin = new class extends AbstractPlugin
    {
        public function name(): string
        {
            return 'minimal';
        }

        public function description(): string
        {
            return 'A plugin that overrides nothing.';
        }

        public function toolPrefix(): string
        {
            return 'minimal_';
        }
    };

    expect($plugin->enabledByDefault())->toBeFalse()
        ->and($plugin->requiredPackage())->toBeNull()
        ->and($plugin->tools())->toBe([])
        ->and($plugin->requiresReadOnlyConnection())->toBeFalse()
        ->and($plugin->bootRules())->toBe([])
        ->and($plugin->prompts())->toBe([]);
});
