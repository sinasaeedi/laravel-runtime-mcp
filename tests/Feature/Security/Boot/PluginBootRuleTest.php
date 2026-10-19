<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use SinaSaeedi\RuntimeMcp\Plugins\Core\CorePlugin;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootIssue;
use SinaSaeedi\RuntimeMcp\Security\Boot\RuntimeMcpMisconfigured;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Boot\FailingRule;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Plugins\FakeRulesPlugin;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Plugins\FakeThrowingRulesPlugin;
use SinaSaeedi\RuntimeMcp\Tests\Support\BootCapture;

/*
 * Spec 4.2: a plugin can add boot validation rules. They run only while the plugin is active, and
 * they act exactly like the core rules.
 */

it('keeps the endpoint off for a failing plugin rule in a strict environment', function () {
    $boot = BootCapture::boot([], 'production', BootCapture::plugins([CorePlugin::class, FakeRulesPlugin::class]));

    expect($boot->thrown)->toBeNull()
        ->and(Route::has('runtime-mcp'))->toBeFalse()
        ->and($boot->criticals())->toHaveCount(1)
        ->and(array_map(fn (BootIssue $issue): string => $issue->ruleId, $boot->events[0]->issues ?? []))->toBe([FailingRule::ID]);
});

it('throws for a failing plugin rule in a relaxed environment', function () {
    $boot = BootCapture::boot([], 'local', BootCapture::plugins([CorePlugin::class, FakeRulesPlugin::class]));

    expect($boot->thrown)->toBeInstanceOf(RuntimeMcpMisconfigured::class)
        ->and($boot->thrown?->getMessage())->toContain('- [fixture.failing] '.FailingRule::MESSAGE);
});

it('does not run the rules of a disabled plugin', function () {
    $boot = BootCapture::boot(['runtime-mcp.plugins.fake_rules' => false], 'production', BootCapture::plugins([CorePlugin::class, FakeRulesPlugin::class]));

    expect($boot->log->records)->toBe([])
        ->and(Route::has('runtime-mcp'))->toBeTrue();
});

it('fails closed when a plugin cannot list its rules', function () {
    $boot = BootCapture::boot([], 'production', BootCapture::plugins([CorePlugin::class, FakeThrowingRulesPlugin::class]));

    expect($boot->thrown)->toBeNull()
        ->and(Route::has('runtime-mcp'))->toBeFalse()
        ->and($boot->events[0]->issues[0]->message ?? null)->toBe('Rule plugins.fake_throwing_rules could not run: RuntimeException.');
});
