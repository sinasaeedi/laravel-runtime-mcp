<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Security\Auth\AuthMethod;
use SinaSaeedi\RuntimeMcp\Security\Auth\LinkedUserMissing;
use SinaSaeedi\RuntimeMcp\Security\Auth\PrincipalResolver;
use SinaSaeedi\RuntimeMcp\Tests\Support\Users;

/** Pest test-file functions are global, so each helper name starts with "principalResolver". */
function principalResolverError(string $name): ?LinkedUserMissing
{
    try {
        app(PrincipalResolver::class)->resolve($name, AuthMethod::ApiKey);
    } catch (LinkedUserMissing $e) {
        return $e;
    }

    return null;
}

it('builds a plain principal for a name that is not linked', function (mixed $links) {
    config(['runtime-mcp.auth.link_users' => $links]);

    $principal = app(PrincipalResolver::class)->resolve('sina', AuthMethod::Stdio);

    expect($principal->name)->toBe('sina')
        ->and($principal->method)->toBe(AuthMethod::Stdio)
        ->and($principal->user)->toBeNull();
})->with([
    'the default' => [[]],
    'null' => null,
    'another name is linked' => [['ops' => 'ops@example.com']],
]);

it('links the user that the app user provider finds by email', function () {
    $user = Users::create('sina@example.com');
    Users::create('ops@example.com');
    config(['runtime-mcp.auth.link_users' => ['sina' => 'sina@example.com']]);

    $principal = app(PrincipalResolver::class)->resolve('sina', AuthMethod::ApiKey);

    expect($principal->user?->getAuthIdentifier())->toBe($user->getAuthIdentifier())
        ->and($principal->subject())->toBe($principal->user)
        ->and($principal->userId())->toBe($user->getAuthIdentifier());
});

it('fails closed when the linked user does not exist, and never names the email', function () {
    Users::create('ops@example.com');
    config(['runtime-mcp.auth.link_users' => ['sina' => 'sina@example.com']]);

    $error = principalResolverError('sina');

    expect($error)->toBeInstanceOf(LinkedUserMissing::class)
        ->and($error?->name)->toBe('sina')
        ->and($error?->getMessage())->toBe('Key [sina] is linked to a user that cannot be found.');
});

it('fails closed for a link that is not a non-empty string', function (mixed $email) {
    // Users whose email is empty or blank: a lookup with that email would find one.
    Users::create('');
    Users::create('   ');
    config(['runtime-mcp.auth.link_users' => ['sina' => $email]]);

    expect(principalResolverError('sina'))->toBeInstanceOf(LinkedUserMissing::class);
})->with([
    'empty' => '',
    'spaces' => '   ',
    'null' => null,
    'int' => 5,
    'array' => [['sina@example.com']],
]);

it('fails closed for every name when link_users is not an array', function (mixed $links) {
    config(['runtime-mcp.auth.link_users' => $links]);

    expect(principalResolverError('anyone'))->toBeInstanceOf(LinkedUserMissing::class);
})->with([
    'string' => 'sina@example.com',
    'int' => 5,
    'true' => true,
]);

it('fails closed when the user provider cannot look the user up', function (array $extra) {
    config(['runtime-mcp.auth.link_users' => ['sina' => 'sina@example.com']]);

    foreach ($extra as $key => $value) {
        config([(string) $key => $value]);
    }

    $error = principalResolverError('sina');

    expect($error)->toBeInstanceOf(LinkedUserMissing::class)
        ->and($error?->getPrevious())->not->toBeNull()
        ->and($error?->getMessage())->not->toContain('sina@example.com');
})->with([
    'unknown provider driver' => [['auth.providers.users.driver' => 'nope']],
    'no users table' => [[]], // this test creates no table
    // Sanctum's guard has 'provider' => null, and Laravel's auth config has no defaults.provider.
    'a default guard with no provider' => [['auth.guards.api' => ['driver' => 'sanctum', 'provider' => null], 'auth.defaults.guard' => 'api']],
    'a provider name with no entry' => [['auth.guards.web.provider' => 'admins']],
]);
