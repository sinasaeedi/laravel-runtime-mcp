<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootGuard;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootIssue;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootState;
use SinaSaeedi\RuntimeMcp\Security\Boot\Rules\AuthDriver;
use SinaSaeedi\RuntimeMcp\Security\Boot\Rules\SanctumInstalled;
use SinaSaeedi\RuntimeMcp\Security\Boot\Severity;
use SinaSaeedi\RuntimeMcp\Tests\Support\BootCapture;
use SinaSaeedi\RuntimeMcp\Tests\Support\Tokens;

/*
 * Spec 5.11 (01-35): "auth.driver is not api_key or sanctum, or it is sanctum and laravel/sanctum is
 * not installed". laravel/sanctum is a dev dependency here, so a missing package is a rule built with
 * a package name that is not installed (authRulesMissingSanctum()).
 */

/** @return list<array{string, string, string}> rule id, severity, message */
function authRulesFlat(BootIssue ...$issues): array
{
    return array_values(array_map(fn (BootIssue $issue): array => [$issue->ruleId, $issue->severity->value, $issue->message], $issues));
}

/** Pre-boot setup: the container builds SanctumInstalled for a package that is not installed. */
function authRulesMissingSanctum(): Closure
{
    return function (Application $app): void {
        $app->bind(SanctumInstalled::class, fn (Application $app): SanctumInstalled => new SanctumInstalled(
            $app->make(Repository::class), 'acme/not-installed',
        ));
    };
}

it('passes auth.driver for api_key and sanctum', function (string $driver) {
    config(['runtime-mcp.auth.driver' => $driver]);

    expect(app(AuthDriver::class)->check())->toBe([]);
})->with(['api_key', 'sanctum']);

it('fails auth.driver for any other value, and prints only a name-like value', function (mixed $driver, string $message) {
    config(['runtime-mcp.auth.driver' => $driver]);

    expect(authRulesFlat(...app(AuthDriver::class)->check()))->toBe([['auth.driver', Severity::Error->value, $message]]);
})->with([
    'passport' => ['passport', 'auth.driver is `passport`. This version supports `api_key` and `sanctum`; `passport` arrives in v1.12.0.'],
    'another name' => ['nope', 'auth.driver is `nope`. This version supports `api_key` and `sanctum`.'],
    'another case' => ['Sanctum', 'auth.driver is not a driver name. This version supports `api_key` and `sanctum`.'],
    'not a name' => ['rmcp_'.str_repeat('Q', 43).'!', 'auth.driver is not a driver name. This version supports `api_key` and `sanctum`.'],
    'too long' => [str_repeat('d', 33), 'auth.driver is not a driver name. This version supports `api_key` and `sanctum`.'],
    'not a string' => [['api_key'], 'auth.driver is not a driver name. This version supports `api_key` and `sanctum`.'],
    'null' => [null, 'auth.driver is not a driver name. This version supports `api_key` and `sanctum`.'],
    'true (a loose compare would match api_key)' => [true, 'auth.driver is not a driver name. This version supports `api_key` and `sanctum`.'],
]);

it('passes auth.sanctum.installed when Sanctum is installed, or the driver is not sanctum', function () {
    config(['runtime-mcp.auth.driver' => 'sanctum']);
    expect(app(SanctumInstalled::class)->check())->toBe([]);

    config(['runtime-mcp.auth.driver' => 'api_key']);
    expect((new SanctumInstalled(app(Repository::class), 'acme/not-installed'))->check())->toBe([]);
});

it('fails auth.sanctum.installed for the sanctum driver without the package', function () {
    config(['runtime-mcp.auth.driver' => 'sanctum']);

    expect(authRulesFlat(...(new SanctumInstalled(app(Repository::class), 'acme/not-installed'))->check()))->toBe([[
        'auth.sanctum.installed',
        Severity::Error->value,
        'auth.driver is `sanctum`, but `acme/not-installed` is not installed. Run composer require acme/not-installed.',
    ]])
        ->and(SanctumInstalled::PACKAGE)->toBe('laravel/sanctum');
});

it('keeps the endpoint off in a strict environment, with one critical line, and never throws', function (string $rule, array $config, bool $missing) {
    /** @var array<string, mixed> $config */
    $boot = BootCapture::boot($config, 'production', $missing ? authRulesMissingSanctum() : null);

    expect($boot->thrown)->toBeNull()
        ->and(Route::has('runtime-mcp'))->toBeFalse()
        ->and(Mcp::getLocalServer('runtime-mcp'))->toBeNull()
        ->and(app(BootState::class)->report()?->passed())->toBeFalse()
        ->and($boot->criticals())->toBe([BootGuard::CRITICAL])
        ->and(array_map(fn (BootIssue $issue): string => $issue->ruleId, $boot->events[0]->issues ?? []))->toBe([$rule]);

    currentTest()->mcpPost('initialize')->assertNotFound();
})->with([
    'auth.driver' => ['auth.driver', ['runtime-mcp.auth.driver' => 'passport', 'runtime-mcp.auth.stdio_principal' => 'local-dev'], false],
    'auth.sanctum.installed' => ['auth.sanctum.installed', [...Tokens::config(), 'runtime-mcp.auth.stdio_principal' => 'local-dev'], true],
]);

it('throws in a relaxed environment', function (string $rule, array $config, bool $missing) {
    /** @var array<string, mixed> $config */
    $boot = BootCapture::boot($config, 'local', $missing ? authRulesMissingSanctum() : null);

    expect($boot->thrown)->not->toBeNull()
        ->and(array_map(fn (BootIssue $issue): string => $issue->ruleId, $boot->thrown?->report->errors() ?? []))->toBe([$rule]);
})->with([
    'auth.driver' => ['auth.driver', ['runtime-mcp.auth.driver' => 'passport'], false],
    'auth.sanctum.installed' => ['auth.sanctum.installed', Tokens::config(), true],
]);

it('registers the route for the sanctum driver with no API key', function () {
    $boot = BootCapture::boot([...Tokens::config(), 'runtime-mcp.auth.api_keys' => null], 'production');

    expect($boot->thrown)->toBeNull()
        ->and($boot->criticals())->toBe([])
        ->and(Route::has('runtime-mcp'))->toBeTrue();
});
