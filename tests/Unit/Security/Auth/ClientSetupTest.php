<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Security\Auth\ClientSetup;

/*
 * Spec 4.6, 7.2 (01-27): the server name, the URL, the client command and the inspector hint that both
 * runtime-mcp:key and runtime-mcp:token print. TestCase sets app.name = 'Runtime Test' and app.url = 'https://app.test'.
 * Helpers are global in Pest: each starts with "clientSetup".
 */

const CLIENT_SETUP_KEY = 'rmcp_AbC-_123abcABC456defDEF789ghiGHI012jklJKL3';

/** @param  array<string, mixed>  $config */
function clientSetupFor(array $config = [], string $environment = 'staging'): ClientSetup
{
    currentTest()->bootWith($config, $environment);

    return app(ClientSetup::class);
}

it('CS1 names the server slug(app name)-slug(environment)', function (string $app, string $environment, string $name) {
    expect(clientSetupFor(['app.name' => $app], $environment)->serverName())->toBe($name);
})->with([
    'spec 7.2' => ['MyApp', 'staging', 'myapp-staging'],
    'two words, a dash' => ['Avica Admin', 'prod-eu', 'avica-admin-prod-eu'],
    'underscore' => ['Runtime Test', 'prod_eu', 'runtime-test-prod-eu'],
    'brackets' => ['My App (EU)', 'production', 'my-app-eu-production'],
    'accents' => ['Ünïcödé App', 'uat', 'unicode-app-uat'],
    'a dot' => ['Shop', 'prod.eu', 'shop-prodeu'],
]);

it('CS2 drops letters with no ASCII form, and falls back to app and env for an empty part', function (string $app, string $environment, string $name) {
    expect(clientSetupFor(['app.name' => $app], $environment)->serverName())->toBe($name);
})->with([
    'no ASCII letter' => ['中文应用', '***', 'app-env'],
    'some letters with no ASCII form' => ['a 中 한 b', 'staging', 'a-b-staging'],
    'Georgian becomes ASCII' => ['ა', 'staging', 'a-staging'],
    'only dashes' => ['---', 'staging', 'app-staging'],
]);

it('CS3 takes the app name from RuntimeServer::appName(), as the server name does', function (mixed $app) {
    $client = clientSetupFor(['app.name' => $app]);

    expect($client->serverName())->toBe('laravel-staging')
        ->and($client->serverLabel())->toBe('Laravel (staging)')
        ->and($client->environment())->toBe('staging');
})->with([null, '', 42]);

it('CS4 builds the URL from APP_URL and runtime-mcp.path, with one "/" between them', function (mixed $url, mixed $path, string $expected) {
    expect(clientSetupFor(['app.url' => $url, 'runtime-mcp.path' => $path])->url())->toBe($expected);
})->with([
    'the default' => ['https://app.test', '/mcp', 'https://app.test/mcp'],
    'no leading slash' => ['https://staging.example.com', 'internal/mcp/', 'https://staging.example.com/internal/mcp'],
    'a trailing slash on APP_URL' => ['https://staging.example.com/', '/internal/mcp', 'https://staging.example.com/internal/mcp'],
    'APP_URL with a folder' => ['https://example.com/app/', 'mcp', 'https://example.com/app/mcp'],
    'APP_URL with spaces' => ['  https://example.com  ', '/mcp', 'https://example.com/mcp'],
]);

it('CS5 uses /mcp and warns when runtime-mcp.path registers no route', function (mixed $path) {
    $client = clientSetupFor(['runtime-mcp.enabled' => true, 'runtime-mcp.path' => $path], 'testing');

    expect($client->url())->toBe('https://app.test/mcp')
        ->and($client->warnings())->toBe([ClientSetup::PATH_WARNING]);
})->with(['empty' => [''], 'only slashes' => ['//'], 'not a string' => [5]]);

it('CS6 uses a placeholder and warns when APP_URL is not set', function (mixed $url) {
    $client = clientSetupFor(['runtime-mcp.enabled' => true, 'app.url' => $url], 'testing');

    expect($client->url())->toBe('https://<your-host>/mcp')
        ->and($client->warnings())->toBe([ClientSetup::APP_URL_WARNING])
        ->and($client->clientCommand(CLIENT_SETUP_KEY))->toContain(" 'https://<your-host>/mcp' ");
})->with(['null' => [null], 'empty' => [''], 'blank' => ['  '], 'not a string' => [['https://app.test']]]);

it('CS7 warns when the endpoint is off, and gives no warning when all is set', function () {
    expect(clientSetupFor(['runtime-mcp.enabled' => false])->warnings())->toBe([ClientSetup::ENDPOINT_WARNING])
        ->and(clientSetupFor(['runtime-mcp.enabled' => 'true'])->warnings())->toBe([ClientSetup::ENDPOINT_WARNING])
        ->and(clientSetupFor(['runtime-mcp.enabled' => true], 'testing')->warnings())->toBe([]);
});

it('CS8 prints the client command in the exact form of spec 7.2', function () {
    $client = clientSetupFor(['app.name' => 'MyApp', 'app.url' => 'https://staging.example.com']);

    expect($client->clientCommand(CLIENT_SETUP_KEY))->toBe(
        'claude mcp add --transport http myapp-staging https://staging.example.com/mcp --header "Authorization: Bearer '.CLIENT_SETUP_KEY.'"',
    );
});

it('CS9 single-quotes a URL with a character the shell would read', function (string $url, string $word) {
    $command = clientSetupFor(['app.url' => $url])->clientCommand(CLIENT_SETUP_KEY);

    expect($command)->toBe('claude mcp add --transport http runtime-test-staging '.$word.' --header "Authorization: Bearer '.CLIENT_SETUP_KEY.'"');
})->with([
    'a port is fine bare' => ['https://example.com:8443', 'https://example.com:8443/mcp'],
    'an ampersand' => ['https://example.com/a&b', "'https://example.com/a&b/mcp'"],
    'a space' => ['https://example.com/a b', "'https://example.com/a b/mcp'"],
    'a single quote' => ["https://example.com/o'k", "'https://example.com/o'\\''k/mcp'"],
]);

it('CS10 keeps a Sanctum token in double quotes, and single-quotes a header the shell would change', function (string $secret, string $word) {
    expect(clientSetupFor()->clientCommand($secret))
        ->toBe('claude mcp add --transport http runtime-test-staging https://app.test/mcp --header '.$word);
})->with([
    'a Sanctum token' => ['12|abcDEF123', '"Authorization: Bearer 12|abcDEF123"'],
    'a dollar sign' => ['12|a$b', "'Authorization: Bearer 12|a\$b'"],
    'a backtick' => ['12|a`b', "'Authorization: Bearer 12|a`b'"],
    'a single quote' => ["12|a'b", "'Authorization: Bearer 12|a'\\''b'"],
    'an exclamation mark' => ['12|a!b', "'Authorization: Bearer 12|a!b'"],
]);

it('CS11 prints the inspector hint with the transport, the URL and the header', function () {
    expect(clientSetupFor()->inspectorHint(CLIENT_SETUP_KEY))
        ->toBe('→ Transport: Streamable HTTP · URL: https://app.test/mcp · Header: Authorization: Bearer '.CLIENT_SETUP_KEY);
});
