<?php

declare(strict_types=1);

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Testing\TestResponse;
use SinaSaeedi\RuntimeMcp\Audit\AuditUnavailable;
use SinaSaeedi\RuntimeMcp\Audit\Events\AuthFailed;
use SinaSaeedi\RuntimeMcp\Audit\Reason;
use SinaSaeedi\RuntimeMcp\Security\Http\EnsureAllowedHostAndOrigin;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\ProtocolEra;
use SinaSaeedi\RuntimeMcp\Tests\TestCase;
use Symfony\Component\HttpFoundation\Response;

/*
 * Pest test-file functions are global, so each helper name starts with "hostOrigin".
 *
 * The Host of a test request comes from its URL: Request::create() overwrites a Host header. A
 * relative path goes to app.url (https://app.test, TestCase::prepareUrlForRequest()), so a full URL
 * picks another host. TrustProxies::at() is static: afterEach() resets it, and the trusted proxies
 * that the last request left on Symfony's Request.
 */

beforeEach(fn () => AuditLog::clear());

afterEach(function () {
    AuditLog::clear();
    TrustProxies::flushState();
    Request::setTrustedProxies([], 0);
});

/**
 * The endpoint on, plus a dataset row's config. A dataset closure cannot type its array for PHPStan,
 * so the keys pass through this typed helper.
 *
 * @param  array<array-key, mixed>  $config
 * @return array<string, mixed>
 */
function hostOriginBootConfig(array $config = []): array
{
    $boot = ['runtime-mcp.enabled' => true];

    foreach ($config as $key => $value) {
        $boot[(string) $key] = $value;
    }

    return $boot;
}

/**
 * tools/list with the test key, to $url (a relative path goes to https://app.test).
 *
 * @param  array<string, string>  $headers
 * @return TestResponse<Response>
 */
function hostOriginPost(
    string $url = '/mcp',
    array $headers = [],
    ProtocolEra $era = ProtocolEra::Legacy,
    ?string $key = TestCase::TEST_KEY,
): TestResponse {
    return currentTest()->mcpPost('tools/list', headers: $headers, era: $era, path: $url, key: $key);
}

/**
 * Exactly the one 403 of this story, whatever the reason.
 *
 * @param  TestResponse<Response>  $response
 */
function hostOriginAssertRefused(TestResponse $response): void
{
    $response->assertForbidden()
        ->assertExactJson(['message' => 'Forbidden.'])
        ->assertHeaderMissing('WWW-Authenticate');

    expect($response->getContent())->toBe(EnsureAllowedHostAndOrigin::forbidden()->getContent());
}

/**
 * One warning entry with every field, and one AuthFailed. Call Event::fake() before the request.
 *
 * @param  array<string, string>  $details
 */
function hostOriginAssertAudited(Reason $reason, array $details, string $environment = 'testing', string $ip = '127.0.0.1'): void
{
    $records = AuditLog::records();
    expect($records)->toHaveCount(1)
        ->and($records[0]['level_name'] ?? null)->toBe('WARNING')
        ->and($records[0]['message'] ?? null)->toBe('runtime-mcp.request_rejected');

    $entry = AuditLog::entries()[0];
    unset($entry['timestamp']);
    expect($entry)->toBe([
        'kind' => 'request_rejected',
        'environment' => $environment,
        'principal' => null,
        'user_id' => null,
        'auth_method' => null,
        'ip' => $ip,
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
}

/** @param  TestResponse<Response>  $response */
function hostOriginAssertPassed(TestResponse $response): void
{
    $response->assertOk()->assertJsonPath('result.tools', []);

    expect(AuditLog::lines())->toBe([]);
    Event::assertNotDispatched(AuthFailed::class);
}

it('lets an allowed host without Origin through', function (array $config, string $url, ProtocolEra $era) {
    currentTest()->bootWith(hostOriginBootConfig($config));
    Event::fake([AuthFailed::class]);

    hostOriginAssertPassed(hostOriginPost($url, era: $era));
})->with([
    'the host of app.url' => [[], '/mcp'],
    'upper case' => [[], 'https://APP.TEST/mcp'],
    'http, not https' => [[], 'http://app.test/mcp'],
    'any port when the entry has none' => [[], 'https://app.test:8443/mcp'],
    'local dev on :8000' => [['app.url' => 'http://localhost:8000'], 'http://localhost:8000/mcp'],
    'IPv6' => [['app.url' => 'http://[::1]:8000'], 'http://[::1]:8000/mcp'],
    'an entry with the same port' => [['runtime-mcp.allowed_hosts' => ['app.test:8443']], 'https://app.test:8443/mcp'],
    'the second entry' => [['runtime-mcp.allowed_hosts' => ['other.test', 'mcp.internal']], 'https://mcp.internal/mcp'],
    'a relative path with :// in its query' => [[], '/mcp?next=https://evil.example'],
])->with([ProtocolEra::Legacy, ProtocolEra::Modern]);

it('refuses a host that is not allowed', function (array $config, string $url, string $rawHost, ProtocolEra $era) {
    currentTest()->bootWith(hostOriginBootConfig($config));
    Event::fake([AuthFailed::class]);

    hostOriginAssertRefused(hostOriginPost($url, era: $era));
    hostOriginAssertAudited(Reason::BadHost, ['host' => $rawHost, 'scheme' => 'Bearer']);
})->with([
    'DNS rebinding' => [[], 'https://evil.example/mcp', 'evil.example'],
    'a subdomain' => [[], 'https://evil.app.test/mcp', 'evil.app.test'],
    'the raw value is logged, with its case and port' => [[], 'https://Evil.Example:8443/mcp', 'Evil.Example:8443'],
    'the entry names another port' => [['runtime-mcp.allowed_hosts' => ['app.test:8443']], 'https://app.test:9999/mcp', 'app.test:9999'],
    'the entry names a port, the request uses 443' => [['runtime-mcp.allowed_hosts' => ['app.test:8443']], '/mcp', 'app.test'],
    'allowed_hosts replaces app.url' => [['runtime-mcp.allowed_hosts' => ['mcp.internal']], '/mcp', 'app.test'],
    'app.url empty (fail closed)' => [['app.url' => ''], '/mcp', 'localhost'],
    'app.url with no scheme (fail closed)' => [['app.url' => 'app.test'], 'https://app.test/mcp', 'app.test'],
    'allowed_hosts an empty list (fail closed)' => [['runtime-mcp.allowed_hosts' => []], '/mcp', 'app.test'],
    'allowed_hosts a string (fail closed)' => [['runtime-mcp.allowed_hosts' => 'app.test'], '/mcp', 'app.test'],
])->with([ProtocolEra::Legacy, ProtocolEra::Modern]);

it('refuses an Origin that is not allowed', function (array $config, string $origin, string $logged) {
    currentTest()->bootWith(hostOriginBootConfig($config));
    Event::fake([AuthFailed::class]);

    hostOriginAssertRefused(hostOriginPost(headers: ['Origin' => $origin]));
    hostOriginAssertAudited(Reason::BadOrigin, ['origin' => $logged, 'scheme' => 'Bearer']);
})->with([
    'another site' => [[], 'https://evil.example', 'https://evil.example'],
    'the app\'s own origin (none is allowed by default)' => [[], 'https://app.test', 'https://app.test'],
    'a sandboxed page' => [[], 'null', 'null'],
    'an empty header' => [[], '', ''],
    'the raw value is logged, not normalised' => [[], 'HTTPS://Evil.Example/', 'HTTPS://Evil.Example/'],
    'a long value is cut to 255 characters' => [[], 'https://'.str_repeat('a', 300).'.test', 'https://'.str_repeat('a', 247)],
    'another scheme' => [['runtime-mcp.allowed_origins' => ['https://console.example.com']], 'http://console.example.com', 'http://console.example.com'],
    'another port' => [['runtime-mcp.allowed_origins' => ['https://console.example.com']], 'https://console.example.com:8443', 'https://console.example.com:8443'],
    'null, when not listed' => [['runtime-mcp.allowed_origins' => ['https://console.example.com']], 'null', 'null'],
    'allowed_origins a string (fail closed)' => [['runtime-mcp.allowed_origins' => 'https://console.example.com'], 'https://console.example.com', 'https://console.example.com'],
]);

it('lets an allowed Origin through', function (array $origins, string $origin) {
    currentTest()->bootWith(hostOriginBootConfig(['runtime-mcp.allowed_origins' => $origins]));
    Event::fake([AuthFailed::class]);

    hostOriginAssertPassed(hostOriginPost(headers: ['Origin' => $origin]));
})->with([
    'exact' => [['https://console.example.com'], 'https://console.example.com'],
    'another case' => [['https://console.example.com'], 'HTTPS://Console.Example.com'],
    'an entry with a trailing slash' => [['https://console.example.com/'], 'https://console.example.com'],
    'an entry with the default port' => [['https://console.example.com:443'], 'https://console.example.com'],
    'the second entry' => [['https://a.example', 'http://localhost:5173'], 'http://localhost:5173'],
    'null, when listed' => [['null'], 'null'],
]);

it('checks the host first: a bad host with a bad Origin is one bad_host entry', function () {
    currentTest()->bootWith(hostOriginBootConfig());
    Event::fake([AuthFailed::class]);

    hostOriginAssertRefused(hostOriginPost('https://evil.example/mcp', ['Origin' => 'https://evil.example']));
    hostOriginAssertAudited(Reason::BadHost, ['host' => 'evil.example', 'scheme' => 'Bearer']);
});

it('refuses a bad host before auth runs: 403, not 401', function () {
    currentTest()->bootWith(hostOriginBootConfig());
    Event::fake([AuthFailed::class]);

    hostOriginAssertRefused(hostOriginPost('https://evil.example/mcp', key: null));
    hostOriginAssertAudited(Reason::BadHost, ['host' => 'evil.example']);
});

it('lets a request with the right host and no Origin reach auth', function (?string $key, Reason $reason) {
    currentTest()->bootWith(hostOriginBootConfig());

    hostOriginPost(key: $key)->assertUnauthorized();

    expect(array_column(AuditLog::entries(), 'reason'))->toBe([$reason->value]);
})->with([
    'no key' => [null, Reason::MissingCredentials],
    'a wrong key' => ['rmcp_'.'wrong', Reason::UnknownKey],
]);

// Spec 5.7: never the credential. A window of 12 characters catches a cut copy, not only the whole key.
it('records the scheme word by 01-06\'s rule, never the credential', function (?string $authorization, ?string $scheme) {
    currentTest()->bootWith(hostOriginBootConfig());
    $headers = $authorization === null ? [] : ['Authorization' => $authorization];

    hostOriginAssertRefused(hostOriginPost('https://evil.example/mcp', $headers, key: null));

    $file = implode("\n", AuditLog::lines());
    $leaks = [];

    for ($i = 0; $i + 12 <= strlen(TestCase::TEST_KEY); $i++) {
        if (str_contains($file, substr(TestCase::TEST_KEY, $i, 12))) {
            $leaks[] = $i;
        }
    }

    expect($leaks)->toBe([])
        ->and(str_contains($file, 'dXNlcjpwYXNz'))->toBeFalse()
        ->and(AuditLog::entries()[0]['details'] ?? null)
        ->toBe($scheme === null ? ['host' => 'evil.example'] : ['host' => 'evil.example', 'scheme' => $scheme]);
})->with([
    'Bearer' => ['Bearer '.TestCase::TEST_KEY, 'Bearer'],
    'lower-case bearer' => ['bearer '.TestCase::TEST_KEY, 'Bearer'],
    'a bare key' => [TestCase::TEST_KEY, '[none]'],
    'Basic' => ['Basic dXNlcjpwYXNz', 'Basic'],
    'leading spaces' => ['  Basic dXNlcjpwYXNz', 'Basic'],
    'a non-ASCII scheme' => ['Bé '.TestCase::TEST_KEY, '[invalid]'],
    'a blank header' => ['   ', null],
    'no header' => [null, null],
]);

it('reads X-Forwarded-Host from a trusted proxy', function () {
    currentTest()->bootWith(hostOriginBootConfig());
    Event::fake([AuthFailed::class]);
    TrustProxies::at(['127.0.0.1']);

    hostOriginAssertPassed(hostOriginPost('https://lb.internal/mcp', ['X-Forwarded-Host' => 'app.test']));
});

it('refuses a forwarded host from a trusted proxy and logs both names and the client IP', function () {
    currentTest()->bootWith(hostOriginBootConfig());
    Event::fake([AuthFailed::class]);
    TrustProxies::at(['127.0.0.1']);

    hostOriginAssertRefused(hostOriginPost('https://lb.internal/mcp', [
        'X-Forwarded-Host' => 'evil.example',
        'X-Forwarded-For' => '203.0.113.9',
    ]));
    hostOriginAssertAudited(Reason::BadHost, ['host' => 'lb.internal', 'forwarded_host' => 'evil.example', 'scheme' => 'Bearer'], ip: '203.0.113.9');
});

it('ignores X-Forwarded-Host from a client that is not a trusted proxy', function () {
    currentTest()->bootWith(hostOriginBootConfig());
    Event::fake([AuthFailed::class]);
    TrustProxies::at(['10.0.0.1']);

    hostOriginAssertRefused(hostOriginPost('https://evil.example/mcp', ['X-Forwarded-Host' => 'app.test']));
    hostOriginAssertAudited(Reason::BadHost, ['host' => 'evil.example', 'scheme' => 'Bearer']);
});

it('compares the forwarded port from a trusted proxy when the entry names a port', function (?string $port, bool $passes) {
    currentTest()->bootWith(hostOriginBootConfig(['runtime-mcp.allowed_hosts' => ['app.test:8443']]));
    Event::fake([AuthFailed::class]);
    TrustProxies::at(['127.0.0.1']);
    $headers = ['X-Forwarded-Host' => 'app.test'];

    if ($port !== null) {
        $headers['X-Forwarded-Port'] = $port;
    }

    $response = hostOriginPost('https://lb.internal/mcp', $headers);

    $passes ? hostOriginAssertPassed($response) : hostOriginAssertRefused($response);
})->with([
    'the right port' => ['8443', true],
    'another port' => ['9999', false],
    'no port: 443 from the scheme' => [null, false],
]);

it('refuses GET and DELETE with a bad host too: 403, not 405', function () {
    currentTest()->bootWith(hostOriginBootConfig());
    $key = ['Authorization' => 'Bearer '.TestCase::TEST_KEY];

    hostOriginAssertRefused(currentTest()->get('https://evil.example/mcp', $key));
    hostOriginAssertRefused(currentTest()->delete('https://evil.example/mcp', [], $key));

    expect(array_column(AuditLog::entries(), 'reason'))->toBe(['bad_host', 'bad_host']);
});

// Out of scope, pinned: Laravel's router answers OPTIONS before the route group. No data, no CORS grant.
it('leaves OPTIONS to Laravel: no data and no Access-Control-Allow-Origin', function () {
    currentTest()->bootWith(hostOriginBootConfig());

    $response = currentTest()->call('OPTIONS', 'https://evil.example/mcp', server: [
        'HTTP_ORIGIN' => 'https://evil.example',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
    ]);

    $response->assertOk()->assertHeaderMissing('Access-Control-Allow-Origin');
    expect($response->getContent())->toBe('')
        ->and(AuditLog::lines())->toBe([]);
});

it('runs in every environment (rule 12) and names it in the audit', function (string $environment) {
    currentTest()->bootWith(hostOriginBootConfig(), $environment);
    Event::fake([AuthFailed::class]);

    hostOriginAssertRefused(hostOriginPost('https://evil.example/mcp'));
    hostOriginAssertAudited(Reason::BadHost, ['host' => 'evil.example', 'scheme' => 'Bearer'], $environment);
})->with(['local', 'production', 'prod-eu']);

it('still refuses when the audit cannot be written', function () {
    currentTest()->bootWith(hostOriginBootConfig(['logging.channels.runtime-mcp' => [
        'driver' => 'daily',
        'path' => __FILE__.'/no-such-dir/audit.log', // the parent is a file, so even root cannot write
        'formatter' => Monolog\Formatter\JsonFormatter::class,
    ]]));
    Exceptions::fake();
    Event::fake([AuthFailed::class]);

    hostOriginAssertRefused(hostOriginPost('https://evil.example/mcp'));

    Exceptions::assertReported(AuditUnavailable::class);
    Event::assertNotDispatched(AuthFailed::class);
});

// The app's global TrustProxies answers a malformed Host with 400 before routing, so only a
// hand-built request reaches the middleware's catch. Symfony throws once, then returns ''.
it('refuses a malformed Host that reaches the middleware', function (bool $askedBefore) {
    currentTest()->bootWith(hostOriginBootConfig());
    Event::fake([AuthFailed::class]);
    $request = Request::create('https://app.test/mcp', 'POST');
    $request->headers->set('HOST', 'bad host!');

    if ($askedBefore) {
        try {
            $request->getHost();
        } catch (UnexpectedValueException) {
            // The first call throws; the middleware's call now gets ''.
        }
    }

    $reached = false;
    $response = app(EnsureAllowedHostAndOrigin::class)->handle($request, function () use (&$reached): Response {
        $reached = true;

        return new Response('next');
    });

    expect($reached)->toBeFalse()
        ->and($response->getStatusCode())->toBe(403)
        ->and($response->getContent())->toBe(EnsureAllowedHostAndOrigin::forbidden()->getContent());
    hostOriginAssertAudited(Reason::BadHost, ['host' => 'bad host!']);
})->with([
    'the first call throws' => [false],
    'a later call returns an empty host' => [true],
]);

// Without a Host header Symfony uses SERVER_NAME. The log says what the client sent: no host.
it('checks SERVER_NAME when there is no Host header, and logs an empty host', function () {
    currentTest()->bootWith(hostOriginBootConfig());
    Event::fake([AuthFailed::class]);
    $request = Request::create('https://evil.example/mcp', 'POST');
    $request->headers->remove('HOST');

    $response = app(EnsureAllowedHostAndOrigin::class)->handle($request, fn (): Response => new Response('next'));

    expect($response->getStatusCode())->toBe(403);
    hostOriginAssertAudited(Reason::BadHost, ['host' => '']);
});

// An empty X-Forwarded-Host from a trusted proxy: getHost() returns '', and getPort() raises a PHP
// warning that Laravel turns into an ErrorException. With the default cors.paths, Laravel's global
// HandleCors meets the same warning first (500 before the route group); with cors.paths = [] the
// request reaches this check, which must refuse it.
it('refuses an empty X-Forwarded-Host from a trusted proxy', function () {
    currentTest()->bootWith(hostOriginBootConfig(['cors.paths' => []]));
    Event::fake([AuthFailed::class]);
    TrustProxies::at(['127.0.0.1']);

    hostOriginAssertRefused(hostOriginPost('https://lb.internal/mcp', ['X-Forwarded-Host' => '']));
    hostOriginAssertAudited(Reason::BadHost, ['host' => 'lb.internal', 'scheme' => 'Bearer']);
});

// The other RequestExceptionInterface: a trusted Forwarded header that disagrees with
// X-Forwarded-Host. Laravel's default header set does not trust Forwarded, so the request is built
// by hand, as in F17.
it('refuses conflicting forwarded headers that reach the middleware', function () {
    currentTest()->bootWith(hostOriginBootConfig());
    Event::fake([AuthFailed::class]);
    Request::setTrustedProxies(['127.0.0.1'], Request::HEADER_FORWARDED | Request::HEADER_X_FORWARDED_HOST);
    $request = Request::create('https://lb.internal/mcp', 'POST');
    $request->headers->set('Forwarded', 'host=evil.example');
    $request->headers->set('X-Forwarded-Host', 'app.test');

    $reached = false;
    $response = app(EnsureAllowedHostAndOrigin::class)->handle($request, function () use (&$reached): Response {
        $reached = true;

        return new Response('next');
    });

    expect($reached)->toBeFalse()
        ->and($response->getStatusCode())->toBe(403);
    hostOriginAssertAudited(Reason::BadHost, ['host' => 'lb.internal']);
});
