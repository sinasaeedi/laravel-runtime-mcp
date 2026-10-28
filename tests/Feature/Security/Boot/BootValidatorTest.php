<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use SinaSaeedi\RuntimeMcp\Plugins\Contracts\Plugin;
use SinaSaeedi\RuntimeMcp\Plugins\Core\CorePlugin;
use SinaSaeedi\RuntimeMcp\Plugins\PluginRegistry;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootIssue;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootRule;
use SinaSaeedi\RuntimeMcp\Security\Boot\Rules\ApiKeysFormat;
use SinaSaeedi\RuntimeMcp\Security\Boot\Rules\ApiKeysPresent;
use SinaSaeedi\RuntimeMcp\Security\Boot\Rules\AuthDriver;
use SinaSaeedi\RuntimeMcp\Security\Boot\Rules\EnvironmentName;
use SinaSaeedi\RuntimeMcp\Security\Boot\Rules\PluginsInstalled;
use SinaSaeedi\RuntimeMcp\Security\Boot\Rules\ReadOnlyConnection;
use SinaSaeedi\RuntimeMcp\Security\Boot\Rules\SanctumInstalled;
use SinaSaeedi\RuntimeMcp\Security\BootValidator;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Boot\FailingRule;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Boot\InvalidResultRule;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Boot\ThrowingRule;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Boot\WarningRule;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Plugins\FakeBasePlugin;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Plugins\FakeRulesPlugin;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Plugins\FakeThrowingRulesPlugin;

/*
 * Spec 5.11: BootValidator runs the core rules, then the active plugins' rules, and never throws.
 * The endpoint stays disabled here, so the provider validates nothing; each test calls validate().
 */

/** @param  list<class-string<Plugin>>  $plugins */
function bootValidatorPlugins(array $plugins): void
{
    app()->scoped(PluginRegistry::class, fn (Application $app): PluginRegistry => new PluginRegistry(
        $app, $app->make(Repository::class), $plugins,
    ));
}

/**
 * @param  list<class-string<BootRule>>|null  $rules
 * @return list<string> "id:severity" per issue
 */
function bootValidatorIssues(?array $rules = null): array
{
    $validator = $rules === null
        ? app(BootValidator::class)
        : new BootValidator(app(), app(Repository::class), app(PluginRegistry::class), $rules);

    return array_map(
        fn (BootIssue $issue): string => $issue->ruleId.':'.$issue->severity->value,
        $validator->validate()->issues,
    );
}

it('lists the core rules of v1.0.0, in order', function () {
    expect(BootValidator::RULES)->toBe([
        ApiKeysPresent::class,
        ApiKeysFormat::class,
        AuthDriver::class, // 01-35
        SanctumInstalled::class, // 01-35
        ReadOnlyConnection::class,
        PluginsInstalled::class,
        EnvironmentName::class, // 01-02 review
    ]);
});

it('passes the harness config with no issue', function () {
    currentTest()->bootWith(environment: 'production');

    $report = app(BootValidator::class)->validate();

    expect($report->issues)->toBe([])
        ->and($report->passed())->toBeTrue()
        ->and($report->httpAllowed())->toBeTrue()
        ->and($report->stdioAllowed())->toBeFalse();
});

it('reports the issues of every rule, in rule order', function () {
    currentTest()->bootWith([
        'runtime-mcp.auth.api_keys' => 'x',
        'runtime-mcp.database.connection' => null,
    ]);

    // api_keys.present passes: a malformed-only ring is not empty.
    expect(bootValidatorIssues())->toBe(['api_keys.format:error', 'database.connection:warning']);
});

it('turns a rule that throws into an error that names only the exception class', function () {
    $report = (new BootValidator(app(), app(Repository::class), app(PluginRegistry::class), [WarningRule::class, ThrowingRule::class, FailingRule::class]))->validate();

    expect(array_map(fn (BootIssue $issue): array => $issue->toArray(), $report->issues))->toBe([
        ['rule' => 'fixture.warning', 'severity' => 'warning', 'message' => WarningRule::MESSAGE],
        ['rule' => 'fixture.throwing', 'severity' => 'error', 'message' => 'Rule fixture.throwing could not run: RuntimeException.'],
        ['rule' => 'fixture.failing', 'severity' => 'error', 'message' => FailingRule::MESSAGE],
    ]);
});

it('runs the rules of active plugins only', function () {
    bootValidatorPlugins([CorePlugin::class, FakeRulesPlugin::class]);

    expect(bootValidatorIssues([]))->toBe(['fixture.failing:error']);

    config(['runtime-mcp.plugins.fake_rules' => false]);
    expect(bootValidatorIssues([]))->toBe([]);
});

it('turns a plugin whose bootRules() throws into an error', function () {
    bootValidatorPlugins([CorePlugin::class, FakeThrowingRulesPlugin::class, FakeRulesPlugin::class]);

    $report = (new BootValidator(app(), app(Repository::class), app(PluginRegistry::class), []))->validate();

    expect(array_map(fn (BootIssue $issue): array => $issue->toArray(), $report->issues))->toBe([
        ['rule' => 'plugins.fake_throwing_rules', 'severity' => 'error', 'message' => 'Rule plugins.fake_throwing_rules could not run: RuntimeException.'],
        ['rule' => 'fixture.failing', 'severity' => 'error', 'message' => FailingRule::MESSAGE],
    ]);
});

it('turns a plugin list that cannot be built into an error', function () {
    bootValidatorPlugins([FakeBasePlugin::class]);
    app()->bind(FakeBasePlugin::class, fn (): stdClass => new stdClass);

    expect(bootValidatorIssues([]))->toBe(['plugins:error']);
});

it('allows HTTP and STDIO by the driver, the key ring and the STDIO principal', function (array $config, bool $passed, bool $http, bool $stdio) {
    $boot = [];

    foreach ($config as $key => $value) {
        $boot[(string) $key] = $value;
    }

    currentTest()->bootWith($boot);
    $report = app(BootValidator::class)->validate();

    expect($report->passed())->toBe($passed)
        ->and($report->httpAllowed())->toBe($http)
        ->and($report->stdioAllowed())->toBe($stdio);
})->with([
    'keys, no principal' => [[], true, true, false],
    'keys and a principal' => [['runtime-mcp.auth.stdio_principal' => 'local-dev'], true, true, true],
    'no keys, a principal (STDIO only)' => [['runtime-mcp.auth.api_keys' => null, 'runtime-mcp.auth.stdio_principal' => 'local-dev'], true, false, true],
    'no keys, no principal' => [['runtime-mcp.auth.api_keys' => null], false, false, false],
    'keys, an invalid principal' => [['runtime-mcp.auth.stdio_principal' => 'local dev'], true, true, false],
    'driver sanctum, no keys' => [['runtime-mcp.auth.driver' => 'sanctum', 'runtime-mcp.auth.api_keys' => null], true, true, false],
    'a malformed ring' => [['runtime-mcp.auth.api_keys' => 'sina:abc', 'runtime-mcp.auth.stdio_principal' => 'local-dev'], false, false, false],
]);

it('turns a rule whose check() returns anything but a list of BootIssue into an error (review 01-10 M2)', function (array $result) {
    app()->bind(InvalidResultRule::class, fn (): InvalidResultRule => new InvalidResultRule($result));

    $report = (new BootValidator(app(), app(Repository::class), app(PluginRegistry::class), [WarningRule::class, InvalidResultRule::class]))->validate();

    expect(array_map(fn (BootIssue $issue): array => $issue->toArray(), $report->issues))->toBe([
        ['rule' => 'fixture.warning', 'severity' => 'warning', 'message' => WarningRule::MESSAGE],
        ['rule' => 'fixture.invalid', 'severity' => 'error', 'message' => 'Rule fixture.invalid returned an invalid result.'],
    ])
        ->and($report->warnings())->toHaveCount(1);
})->with([
    'a string' => [['Horizon is not configured.']],
    'null' => [[null]],
    'an issue as an array' => [[['rule' => 'fixture.invalid', 'severity' => 'error', 'message' => 'm']]],
    'a valid issue and a string' => [[BootIssue::warning('fixture.invalid', 'm'), 'text']],
    'string keys' => [['first' => BootIssue::warning('fixture.invalid', 'm')]],
]);
