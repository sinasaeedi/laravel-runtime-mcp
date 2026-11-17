<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Tests\Support\SourceFiles;

// 01-35: laravel/sanctum is optional (composer.json `suggest`), so only the Sanctum driver, its
// doctor check and the token command (01-27) may use it, and each of them runs only when the Sanctum
// classes exist.
arch('only the auth classes, the Sanctum doctor check and the token command use laravel/sanctum', function () {
    expect('SinaSaeedi\RuntimeMcp')
        ->not->toUse('Laravel\Sanctum')
        ->ignoring([
            'SinaSaeedi\RuntimeMcp\Security\Auth\AuthenticateRequest',
            'SinaSaeedi\RuntimeMcp\Security\Auth\AuthenticateSanctumToken',
            'SinaSaeedi\RuntimeMcp\Doctor\Checks\Sanctum',
            'SinaSaeedi\RuntimeMcp\Console\TokenCommand', // 01-27: it checks the trait as the doctor check does
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
// (01-35 re-review; 01-27 adds the token command).
it('names laravel/sanctum in the four files only', function () {
    $root = dirname(__DIR__, 2).'/src/';
    $files = array_values(array_filter(
        SourceFiles::paths(),
        fn (string $path): bool => str_contains((string) file_get_contents($path), 'Laravel\\Sanctum'),
    ));

    expect(array_map(fn (string $path): string => substr($path, strlen($root)), $files))->toBe([
        'Console/TokenCommand.php',
        'Doctor/Checks/Sanctum.php',
        'Security/Auth/AuthenticateRequest.php',
        'Security/Auth/AuthenticateSanctumToken.php',
    ]);
});
