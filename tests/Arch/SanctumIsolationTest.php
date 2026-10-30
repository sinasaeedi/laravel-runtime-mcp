<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Tests\Support\SourceFiles;

// 01-35: laravel/sanctum is optional (composer.json `suggest`), so only the Sanctum driver and its
// doctor check may use it, and each of them runs only when the Sanctum classes exist.
arch('only the auth classes and the Sanctum doctor check use laravel/sanctum', function () {
    expect('SinaSaeedi\RuntimeMcp')
        ->not->toUse('Laravel\Sanctum')
        ->ignoring([
            'SinaSaeedi\RuntimeMcp\Security\Auth\AuthenticateRequest',
            'SinaSaeedi\RuntimeMcp\Security\Auth\AuthenticateSanctumToken',
            'SinaSaeedi\RuntimeMcp\Doctor\Checks\Sanctum',
        ]);
});

it('keeps laravel/sanctum out of require: it is a suggest and a dev dependency', function () {
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true);
    assert(is_array($composer));

    expect($composer['require'] ?? [])->not->toHaveKey('laravel/sanctum')
        ->and($composer['require-dev'] ?? [])->toHaveKey('laravel/sanctum')
        ->and($composer['suggest'] ?? [])->toHaveKey('laravel/sanctum');
});

// ignoring() is a plain prefix match, so a later Doctor\Checks\SanctumTokens or Security\Auth\
// AuthenticateRequestFactory would pass the rule above. The text check names the three files exactly
// (01-35 re-review).
it('names laravel/sanctum in the three files only', function () {
    $root = dirname(__DIR__, 2).'/src/';
    $files = array_values(array_filter(
        SourceFiles::paths(),
        fn (string $path): bool => str_contains((string) file_get_contents($path), 'Laravel\\Sanctum'),
    ));

    expect(array_map(fn (string $path): string => substr($path, strlen($root)), $files))->toBe([
        'Doctor/Checks/Sanctum.php',
        'Security/Auth/AuthenticateRequest.php',
        'Security/Auth/AuthenticateSanctumToken.php',
    ]);
});
