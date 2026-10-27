<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Security\Environment;

it('reads the strict or relaxed state', function (string $env, mixed $relaxed, bool $isRelaxed) {
    currentTest()->bootWith(['runtime-mcp.environments.relaxed' => $relaxed], $env);

    $environment = app(Environment::class);

    expect($environment->name())->toBe($env)
        ->and($environment->isRelaxed())->toBe($isRelaxed)
        ->and($environment->isStrict())->toBe(! $isRelaxed);
})->with([
    'testing is relaxed by default' => ['testing', ['local', 'testing'], true],
    'local is relaxed by default' => ['local', ['local', 'testing'], true],
    'production is strict' => ['production', ['local', 'testing'], false],
    'staging is strict by default' => ['staging', ['local', 'testing'], false],
    'an unknown name is strict' => ['prod-eu', ['local', 'testing'], false],
    'staging added by choice' => ['staging', ['local', 'testing', 'staging'], true],
    'the match is case-sensitive' => ['Local', ['local'], false],
    'a string is not a list' => ['local', 'local', false],
    'null means none' => ['local', null, false],
    'a map is not a list' => ['local', ['a' => 'local'], false],
    'one non-string entry spoils the list' => ['local', ['local', 1], false],
]);

it('treats a missing relaxed key as an empty list', function () {
    currentTest()->bootWith(environment: 'local');
    config()->offsetUnset('runtime-mcp.environments.relaxed');

    expect(app(Environment::class)->isStrict())->toBeTrue();
});

it('is a scoped binding', function () {
    $first = app(Environment::class);
    expect(app(Environment::class))->toBe($first);
    app()->forgetScopedInstances();

    expect(app(Environment::class))->not->toBe($first);
});
