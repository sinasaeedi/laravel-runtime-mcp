<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;
use SinaSaeedi\RuntimeMcp\Audit\Events\AuthFailed;
use SinaSaeedi\RuntimeMcp\Audit\Reason;
use SinaSaeedi\RuntimeMcp\Security\Auth\AuthenticateRequest;
use SinaSaeedi\RuntimeMcp\Security\Auth\AuthMethod;
use SinaSaeedi\RuntimeMcp\Security\Auth\AuthRejection;
use SinaSaeedi\RuntimeMcp\Security\Auth\CurrentPrincipal;
use SinaSaeedi\RuntimeMcp\Security\Auth\Unauthenticated;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\ProtocolEra;
use SinaSaeedi\RuntimeMcp\Tests\Support\Tokens;
use SinaSaeedi\RuntimeMcp\Tests\TestCase;

/*
 * Spec 4.5, 5.3 (01-35): the auth step picks the driver per request. A bad driver fails boot
 * validation (AuthRulesTest), so these tests change the driver after a boot that passed, as a cached
 * route meets a config change. Helpers start with "authRequest" (Pest functions are global).
 */

beforeEach(fn () => AuditLog::clear());
afterEach(fn () => AuditLog::clear());

/** @return array<array-key, mixed> the one audit entry, without its timestamp */
function authRequestOnlyEntry(): array
{
    $entries = AuditLog::entries();
    expect($entries)->toHaveCount(1);
    $entry = $entries[0];
    unset($entry['timestamp']);

    return $entry;
}

it('follows the driver of the current config on every request', function () {
    currentTest()->bootWith(['runtime-mcp.enabled' => true]);
    Tokens::tables();
    config(['auth.providers.users.model' => Tokens::config()['auth.providers.users.model']]);
    $token = Tokens::issue(Tokens::user());

    currentTest()->mcpPost('tools/list')->assertOk();
    expect(app(CurrentPrincipal::class)->get()?->method)->toBe(AuthMethod::ApiKey);
    currentTest()->mcpPost('tools/list', key: $token)->assertUnauthorized();
    expect(AuditLog::entries()[0]['reason'] ?? null)->toBe(Reason::UnknownKey->value);

    config(['runtime-mcp.auth.driver' => 'sanctum']);
    AuditLog::clear();
    app()->forgetScopedInstances();

    currentTest()->mcpPost('tools/list', key: $token)->assertOk();
    expect(app(CurrentPrincipal::class)->get()?->method)->toBe(AuthMethod::Sanctum);
    currentTest()->mcpPost('tools/list')->assertUnauthorized();
    expect(AuditLog::entries()[0]['reason'] ?? null)->toBe(Reason::UnknownToken->value);
});

it('refuses a driver that is not supported, set after boot', function (mixed $driver, array $details, ProtocolEra $era) {
    currentTest()->bootWith(['runtime-mcp.enabled' => true]);
    config(['runtime-mcp.auth.driver' => $driver]);
    Event::fake([AuthFailed::class]);

    $response = currentTest()->mcpPost('tools/list', era: $era)->assertUnauthorized();

    expect($response->getContent())->toBe(Unauthenticated::response()->getContent())
        ->and($response->headers->get('WWW-Authenticate'))->toBe(Unauthenticated::HEADER)
        ->and(authRequestOnlyEntry())->toMatchArray([
            'kind' => 'request_rejected',
            'principal' => null,
            'auth_method' => null,
            'reason' => Reason::UnsupportedAuthDriver->value,
            'details' => $details,
        ])
        ->and(app(CurrentPrincipal::class)->get())->toBeNull();
    Event::assertDispatchedTimes(AuthFailed::class, 1);
})->with([
    'passport' => ['passport', ['driver' => 'passport']],
    'a long name' => [str_repeat('d', 30), ['driver' => str_repeat('d', 20)]],
    'not a string' => [['api_key'], []],
    'null' => [null, []],
    'another case of api_key' => ['API_KEY', ['driver' => 'API_KEY']],
])->with([ProtocolEra::Legacy, ProtocolEra::Modern]);

it('refuses the sanctum driver when the Sanctum classes are missing', function () {
    currentTest()->bootWith(Tokens::config());
    app()->bind(AuthenticateRequest::class, fn (Application $app): AuthenticateRequest => new AuthenticateRequest(
        $app->make(Repository::class), $app, $app->make(AuthRejection::class), 'Acme\\Missing\\Sanctum',
    ));

    currentTest()->mcpPost('tools/list', key: TestCase::TEST_KEY)->assertUnauthorized();

    expect(authRequestOnlyEntry())->toMatchArray([
        'auth_method' => null,
        'reason' => Reason::UnsupportedAuthDriver->value,
        'details' => ['driver' => 'sanctum'],
    ]);
});
