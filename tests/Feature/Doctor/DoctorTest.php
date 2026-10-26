<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Doctor\Checks\BootRules;
use SinaSaeedi\RuntimeMcp\Doctor\Checks\Endpoint;
use SinaSaeedi\RuntimeMcp\Doctor\CheckStatus;
use SinaSaeedi\RuntimeMcp\Doctor\Doctor;
use SinaSaeedi\RuntimeMcp\Doctor\DoctorLine;
use SinaSaeedi\RuntimeMcp\Plugins\Core\CorePlugin;
use SinaSaeedi\RuntimeMcp\Plugins\PluginRegistry;
use SinaSaeedi\RuntimeMcp\Security\Environment;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Doctor\StaticCheck;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Doctor\ThrowingCheck;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Doctor\UnbuildableCheck;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Plugins\FakeDoctorPlugin;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Plugins\FakeThrowingDoctorPlugin;
use SinaSaeedi\RuntimeMcp\Tests\Support\BootCapture;

/* Spec 7.3, 01-12 Part A: the runner. Core checks in a fixed order, then the active plugins' checks; a crash is one Fail line. */

/** @param  list<DoctorLine>  $lines */
function lastDoctorLine(array $lines): DoctorLine
{
    $last = end($lines);
    assert($last instanceof DoctorLine);

    return $last;
}

/** @return list<string> */
function doctorTitles(): array
{
    return array_values(array_unique(array_map(fn (DoctorLine $line): string => $line->title, app(Doctor::class)->run()->lines)));
}

it('runs the core checks in the order of Doctor::CHECKS', function () {
    currentTest()->bootWith([], 'production');

    expect(doctorTitles())->toBe([
        'Endpoint', 'Boot rules', 'Sanctum', 'Linked users', 'STDIO principal', 'Route',
        'Allowed hosts', 'Allowed origins', 'Redaction patterns', 'Mask lists', 'Read-only role', 'Plugins', 'Audit log',
        'Statement timeout', 'Read-only transactions',
    ])
        ->and(count(Doctor::CHECKS))->toBe(15);
});

it('adds the checks of each active plugin after the core checks', function () {
    currentTest()->bootWith([], 'production', BootCapture::plugins([CorePlugin::class, FakeDoctorPlugin::class]));

    $lines = app(Doctor::class)->run()->lines;
    $plugin = array_values(array_filter($lines, fn (DoctorLine $line): bool => $line->title === 'Fake plugin check'));

    expect(lastDoctorLine($lines)->title)->toBe('Fake plugin check')
        ->and(array_map(fn (DoctorLine $line): string => $line->result->message, $plugin))->toBe(['plugin pass line <info>kept</info>', 'plugin warn line', 'plugin fail line'])
        ->and($plugin[0]->source)->toBe(StaticCheck::class);
});

it('runs no check of a plugin that is off', function () {
    currentTest()->bootWith(['runtime-mcp.plugins.fake_doctor' => false], 'production', BootCapture::plugins([CorePlugin::class, FakeDoctorPlugin::class]));

    expect(doctorTitles())->not->toContain('Fake plugin check');
});

it('turns a check that throws into one Fail line with the class only, and runs the rest', function () {
    currentTest()->bootWith([], 'production');

    $doctor = new Doctor(app(), app(PluginRegistry::class), app(Environment::class), [ThrowingCheck::class, Endpoint::class]);
    $lines = $doctor->run()->lines;

    expect($lines[0]->title)->toBe('Throwing check')
        ->and($lines[0]->result->status)->toBe(CheckStatus::Fail)
        ->and($lines[0]->result->message)->toBe('Check Throwing check could not run: RuntimeException.')
        ->and($lines[0]->source)->toBe(ThrowingCheck::class)
        ->and(str_contains($lines[0]->result->message, ThrowingCheck::SECRET))->toBeFalse()
        ->and($lines[1]->title)->toBe('Endpoint');
});

it('names a check that cannot be built by its class', function () {
    currentTest()->bootWith([], 'production');

    $lines = (new Doctor(app(), app(PluginRegistry::class), app(Environment::class), [UnbuildableCheck::class]))->run()->lines;

    expect($lines)->toHaveCount(1)
        ->and($lines[0]->title)->toBe(UnbuildableCheck::class)
        ->and($lines[0]->result->message)->toBe('Check '.UnbuildableCheck::class.' could not run: Illuminate\Contracts\Container\BindingResolutionException.');
});

it('turns a plugin whose doctorChecks() throws into one Fail line', function () {
    currentTest()->bootWith([], 'production', BootCapture::plugins([CorePlugin::class, FakeThrowingDoctorPlugin::class]));

    $last = lastDoctorLine(app(Doctor::class)->run()->lines);

    expect($last->title)->toBe('Plugin `fake_throwing_doctor`')
        ->and($last->result->message)->toBe('Check Plugin `fake_throwing_doctor` could not run: RuntimeException.')
        ->and($last->source)->toBe(FakeThrowingDoctorPlugin::class);
});

it('marks the boot rule lines with their source, for the footer', function () {
    currentTest()->bootWith(['runtime-mcp.auth.api_keys' => null], 'production');

    $boot = array_values(array_filter(app(Doctor::class)->run()->lines, fn (DoctorLine $line): bool => $line->title === 'Boot rules'));

    expect($boot[0]->source)->toBe(BootRules::class)
        ->and($boot[0]->result->status)->toBe(CheckStatus::Fail);
});

it('takes the relaxed flag from the environment', function (string $environment, bool $relaxed) {
    currentTest()->bootWith([], $environment);

    expect(app(Doctor::class)->run()->relaxed)->toBe($relaxed);
})->with(['production' => ['production', false], 'staging' => ['staging', false], 'local' => ['local', true]]);
