<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Facades\Mcp;
use SinaSaeedi\RuntimeMcp\Audit\Events\AuthFailed;
use SinaSaeedi\RuntimeMcp\Audit\Reason;
use SinaSaeedi\RuntimeMcp\Security\Auth\ApiKey;
use SinaSaeedi\RuntimeMcp\Security\Auth\AuthMethod;
use SinaSaeedi\RuntimeMcp\Security\Auth\CurrentPrincipal;
use SinaSaeedi\RuntimeMcp\Security\Auth\Unauthenticated;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\ProtocolEra;
use SinaSaeedi\RuntimeMcp\Tests\Support\Users;
use SinaSaeedi\RuntimeMcp\Tests\TestCase;
use Symfony\Component\HttpFoundation\Response;

/*
 * Pest test-file functions are global, so each helper name starts with "authApiKey".
 * Key samples are built by concatenation, as in 01-04. TestCase::TEST_KEY is the harness key
 * (named "tests"), which bootWith() puts in runtime-mcp.auth.api_keys.
 */

beforeEach(fn () => AuditLog::clear());
afterEach(fn () => AuditLog::clear());

/** A well-formed key that no ring in this file holds (a key of another server). */
function authApiKeyOther(): string
{
    return 'rmcp_'.str_repeat('Zz09', 10).'abc';
}

/**
 * The endpoint on, plus a dataset row's config. A dataset closure cannot type its array for PHPStan,
 * so the keys pass through this typed helper.
 *
 * @param  array<array-key, mixed>  $config
 * @return array<string, mixed>
 */
function authApiKeyBootConfig(array $config): array
{
    $boot = ['runtime-mcp.enabled' => true];

    foreach ($config as $key => $value) {
        $boot[(string) $key] = $value;
    }

    return $boot;
}

/** @return TestResponse<Response> */
function authApiKeyPost(?string $authorization, ProtocolEra $era = ProtocolEra::Legacy, string $method = 'tools/list'): TestResponse
{
    $headers = $authorization === null ? [] : ['Authorization' => $authorization];

    return currentTest()->mcpPost($method, headers: $headers, era: $era, key: null);
}

/**
 * Exactly the one 401 of spec 5.3, whatever the reason.
 *
 * @param  TestResponse<Response>  $response
 */
function authApiKeyAssertRejected(TestResponse $response): void
{
    $response->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.'])
        ->assertHeader('WWW-Authenticate', 'Bearer realm="runtime-mcp", error="invalid_token"');

    expect($response->getContent())->toBe(Unauthenticated::response()->getContent());
}

it('rejects with the reason in the audit only', function (
    ?string $authorization,
    array $config,
    Reason $reason,
    ?AuthMethod $method,
    array $details,
    ProtocolEra $era,
) {
    currentTest()->bootWith(authApiKeyBootConfig($config));
    Event::fake([AuthFailed::class]);

    authApiKeyAssertRejected(authApiKeyPost($authorization, $era));

    $records = AuditLog::records();
    expect($records)->toHaveCount(1)
        ->and($records[0]['level_name'] ?? null)->toBe('WARNING')
        ->and($records[0]['message'] ?? null)->toBe('runtime-mcp.request_rejected');

    $entry = AuditLog::entries()[0];
    unset($entry['timestamp']);
    expect($entry)->toBe([
        'kind' => 'request_rejected',
        'environment' => 'testing',
        'principal' => null,
        'user_id' => null,
        'auth_method' => $method?->value,
        'ip' => '127.0.0.1',
        'client' => null,
        'tool' => null,
        'params' => [],
        'decision' => 'denied',
        'reason' => $reason->value,
        'outcome' => null,
        'error_class' => null,
        'duration_ms' => null,
        'row_count' => null,
        'output_bytes' => null,
        'details' => $details,
    ]);

    Event::assertDispatchedTimes(AuthFailed::class, 1);
    Event::assertDispatched(AuthFailed::class, fn (AuthFailed $event): bool => $event->entry->reason === $reason->value);
    expect(app(CurrentPrincipal::class)->get())->toBeNull();
})->with([
    'no header' => [null, [], Reason::MissingCredentials, AuthMethod::ApiKey, []],
    'empty header' => ['', [], Reason::MissingCredentials, AuthMethod::ApiKey, []],
    'spaces only' => ['   ', [], Reason::MissingCredentials, AuthMethod::ApiKey, []],
    'Bearer and a space' => ['Bearer ', [], Reason::BlankCredentials, AuthMethod::ApiKey, ['scheme' => 'Bearer']],
    'Bearer alone' => ['Bearer', [], Reason::BlankCredentials, AuthMethod::ApiKey, ['scheme' => 'Bearer']],
    'Bearer and tabs' => ["Bearer \t ", [], Reason::BlankCredentials, AuthMethod::ApiKey, ['scheme' => 'Bearer']],
    'Basic' => ['Basic dXNlcjpwYXNz', [], Reason::UnsupportedScheme, AuthMethod::ApiKey, ['scheme' => 'Basic']],
    'Token' => ['Token '.TestCase::TEST_KEY, [], Reason::UnsupportedScheme, AuthMethod::ApiKey, ['scheme' => 'Token']],
    'bare key, no scheme' => [TestCase::TEST_KEY, [], Reason::UnsupportedScheme, AuthMethod::ApiKey, ['scheme' => '[none]']],
    'scheme with a non-ASCII letter' => ['Bé x', [], Reason::UnsupportedScheme, AuthMethod::ApiKey, ['scheme' => '[invalid]']],
    'scheme of 21 characters' => [str_repeat('A', 21).' x', [], Reason::UnsupportedScheme, AuthMethod::ApiKey, ['scheme' => '[invalid]']],
    'Bearer after another scheme' => ['Basic x, Bearer '.TestCase::TEST_KEY, [], Reason::UnsupportedScheme, AuthMethod::ApiKey, ['scheme' => 'Basic']],
    'wrong key' => ['Bearer rmcp_'.'wrong', [], Reason::UnknownKey, AuthMethod::ApiKey, ['scheme' => 'Bearer']],
    'a key of another server' => ['Bearer '.authApiKeyOther(), [], Reason::UnknownKey, AuthMethod::ApiKey, ['scheme' => 'Bearer']],
    'the key is linked to a missing user' => [
        'Bearer '.TestCase::TEST_KEY,
        ['runtime-mcp.auth.link_users' => ['tests' => 'nobody@example.com']],
        Reason::LinkedUserMissing, AuthMethod::ApiKey, ['scheme' => 'Bearer'],
    ],
    'driver passport' => ['Bearer '.TestCase::TEST_KEY, ['runtime-mcp.auth.driver' => 'passport'], Reason::UnsupportedAuthDriver, null, ['driver' => 'passport']],
    'driver sanctum before 01-35' => ['Bearer '.TestCase::TEST_KEY, ['runtime-mcp.auth.driver' => 'sanctum'], Reason::UnsupportedAuthDriver, null, ['driver' => 'sanctum']],
    'driver with a long name' => ['Bearer '.TestCase::TEST_KEY, ['runtime-mcp.auth.driver' => str_repeat('d', 30)], Reason::UnsupportedAuthDriver, null, ['driver' => str_repeat('d', 20)]],
    'driver not a string' => ['Bearer '.TestCase::TEST_KEY, ['runtime-mcp.auth.driver' => ['api_key']], Reason::UnsupportedAuthDriver, null, []],
])->with([ProtocolEra::Legacy, ProtocolEra::Modern]);

// 01-10: an empty or malformed ring fails boot validation (no route, or a throw in `testing`), so
// these two cases change the ring after boot, as a cached route meets a config change. The ring is
// read per request, so the right key is still refused: 01-06's second lock.
it('rejects the right key when the ring turns empty or malformed after boot', function (mixed $keys, ProtocolEra $era) {
    currentTest()->bootWith(['runtime-mcp.enabled' => true]);
    config(['runtime-mcp.auth.api_keys' => $keys]);
    Event::fake([AuthFailed::class]);

    authApiKeyAssertRejected(authApiKeyPost('Bearer '.TestCase::TEST_KEY, $era));

    $entry = AuditLog::entries()[0] ?? [];
    expect($entry['reason'] ?? null)->toBe(Reason::UnknownKey->value)
        ->and($entry['auth_method'] ?? null)->toBe(AuthMethod::ApiKey->value)
        ->and($entry['details'] ?? null)->toBe(['scheme' => 'Bearer']);
    Event::assertDispatchedTimes(AuthFailed::class, 1);
})->with([
    'the right key in a ring with a malformed entry' => [TestCase::defaultKeyEntry().',sina:abc'],
    'no keys at all' => [null],
])->with([ProtocolEra::Legacy, ProtocolEra::Modern]);

it('sends our header, not laravel/mcp\'s, also when the app uses Mcp::oauthRoutes()', function () {
    currentTest()->bootWith(['runtime-mcp.enabled' => true]);

    Mcp::oauthRoutes();
    app('router')->getRoutes()->refreshNameLookups();
    expect(Route::has('mcp.oauth.protected-resource.nested'))->toBeTrue(); // the OAuth branch is live

    $response = authApiKeyPost(null);

    authApiKeyAssertRejected($response);
    expect($response->headers->get('WWW-Authenticate'))->not->toContain('resource_metadata');
});

// Spec 5.7: never the credential. A window of 12 characters catches a cut copy, not only the whole key.
it('never writes the key, a part of it, or its hash to the audit', function (string $scheme, string $logged) {
    currentTest()->bootWith(['runtime-mcp.enabled' => true]);
    $key = ApiKey::generate();

    authApiKeyAssertRejected(authApiKeyPost($scheme === '' ? $key : $scheme.' '.$key));

    $file = implode("\n", AuditLog::lines());
    $leaks = [];

    for ($i = 0; $i + 12 <= strlen($key); $i++) {
        if (str_contains($file, substr($key, $i, 12))) {
            $leaks[] = $i;
        }
    }

    expect($leaks)->toBe([])
        ->and(str_contains($file, ApiKey::hash($key)))->toBeFalse()
        ->and(AuditLog::entries()[0]['details'] ?? null)->toBe(['scheme' => $logged]);
})->with([
    'Bearer' => ['Bearer', 'Bearer'],
    'Token' => ['Token', 'Token'],
    'no scheme' => ['', '[none]'],
]);

it('lets a valid key reach laravel/mcp, with the key name as the principal', function (ProtocolEra $era) {
    currentTest()->bootWith(['runtime-mcp.enabled' => true]);
    Event::fake([AuthFailed::class]);

    currentTest()->mcpPost($era === ProtocolEra::Legacy ? 'initialize' : 'server/discover', era: $era)->assertOk();
    currentTest()->mcpPost('tools/list', era: $era)->assertOk()->assertJsonPath('result.tools', []);

    $principal = app(CurrentPrincipal::class)->get();
    expect($principal?->name)->toBe(TestCase::TEST_KEY_NAME)
        ->and($principal?->method)->toBe(AuthMethod::ApiKey)
        ->and($principal?->user)->toBeNull()
        ->and(AuditLog::lines())->toBe([]);
    Event::assertNotDispatched(AuthFailed::class);
})->with([ProtocolEra::Legacy, ProtocolEra::Modern]);

it('accepts any case of Bearer and extra spaces or tabs', function (string $authorization) {
    currentTest()->bootWith(['runtime-mcp.enabled' => true]);

    authApiKeyPost($authorization)->assertOk();
})->with([
    'lower case' => ['bearer '.TestCase::TEST_KEY],
    'upper case' => ['BEARER '.TestCase::TEST_KEY],
    'two spaces and a tab' => ["Bearer  \t".TestCase::TEST_KEY],
    'trailing space' => ['Bearer '.TestCase::TEST_KEY.' '],
]);

it('accepts both keys of one name (rotation), empty segments and upper-case hex', function () {
    $old = 'rmcp_'.str_repeat('Old1', 10).'abc';
    $new = 'rmcp_'.str_repeat('New2', 10).'abc';
    currentTest()->bootWith([
        'runtime-mcp.enabled' => true,
        'runtime-mcp.auth.api_keys' => 'sina:'.strtoupper(ApiKey::hash($old)).',  ,sina:'.ApiKey::hash($new).',',
    ]);

    authApiKeyPost('Bearer '.$old)->assertOk();
    expect(app(CurrentPrincipal::class)->get()?->name)->toBe('sina');

    authApiKeyPost('Bearer '.$new)->assertOk();
    expect(app(CurrentPrincipal::class)->get()?->name)->toBe('sina');

    authApiKeyAssertRejected(authApiKeyPost('Bearer '.TestCase::TEST_KEY)); // its own entry is overridden
});

it('runs Gates for the linked user, and never signs anyone in to Laravel', function () {
    currentTest()->bootWith([
        'runtime-mcp.enabled' => true,
        'runtime-mcp.auth.link_users' => [TestCase::TEST_KEY_NAME => 'sina@example.com'],
    ]);
    $user = Users::create('sina@example.com');

    currentTest()->mcpPost('tools/list')->assertOk();

    $principal = app(CurrentPrincipal::class)->get();
    expect($principal?->user?->getAuthIdentifier())->toBe($user->getAuthIdentifier())
        ->and($principal?->subject())->toBe($principal?->user)
        ->and(Auth::guard()->hasUser())->toBeFalse();
});
