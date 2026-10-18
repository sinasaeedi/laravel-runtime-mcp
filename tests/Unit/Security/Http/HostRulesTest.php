<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use SinaSaeedi\RuntimeMcp\Security\Http\HostRules;

/*
 * Pest test-file functions are global, so each helper name starts with "hostRules".
 */

/**
 * Rules from a config that starts with the package defaults and app.url = https://app.test.
 *
 * @param  array<array-key, mixed>  $config
 */
function hostRulesFrom(array $config = []): HostRules
{
    $repository = new Repository([
        'app' => ['url' => 'https://app.test'],
        'runtime-mcp' => ['allowed_hosts' => null, 'allowed_origins' => []],
    ]);

    foreach ($config as $key => $value) {
        $repository->set((string) $key, $value);
    }

    return HostRules::fromConfig($repository);
}

it('resolves the default host from app.url, without its port', function (mixed $url, array $hosts) {
    expect(hostRulesFrom(['app.url' => $url])->hosts())->toBe($hosts);
})->with([
    'https' => ['https://app.test', ['app.test']],
    'upper case and a path' => ['https://App.Test/sub', ['app.test']],
    'a port' => ['http://localhost:8000', ['localhost']],
    'IPv6' => ['http://[::1]:8000', ['[::1]']],
    'empty' => ['', []],
    'no scheme' => ['app.test', []],
    'null' => [null, []],
    'not a string' => [['https://app.test'], []],
]);

it('reads allowed_hosts instead of app.url, and fails closed on a bad value', function (mixed $configured, array $hosts) {
    expect(hostRulesFrom(['runtime-mcp.allowed_hosts' => $configured])->hosts())->toBe($hosts);
})->with([
    'two entries' => [['App.Test ', 'mcp.internal:8443'], ['app.test', 'mcp.internal:8443']],
    'an empty list refuses all (not app.url)' => [[], []],
    'a string' => ['app.test', []],
    'a map' => [['main' => 'app.test'], []],
    'an int' => [1, []],
    'IPv6 with and without a port' => [['[::1]:8443', '[::1]'], ['[::1]:8443', '[::1]']],
    'non-string and blank entries skipped' => [[1, null, '', '   ', 'app.test'], ['app.test']],
    'entries that can never match' => [[
        'https://app.test', 'app.test/mcp', 'user@app.test', '::1', 'app test',
        'app.test:0', 'app.test:65536', 'app.test:', '*.app.test', 'app.test:80:80',
    ], []],
    'the highest port' => [['app.test:65535'], ['app.test:65535']],
]);

it('matches a host, and a port only when the entry names one', function (array $entries, string $host, int|string|null $port, bool $allowed) {
    expect(hostRulesFrom(['runtime-mcp.allowed_hosts' => $entries])->allowsHost($host, $port))->toBe($allowed);
})->with([
    'same host, default port' => [['app.test'], 'app.test', 443, true],
    'same host, any port' => [['app.test'], 'app.test', 9999, true],
    'same host, no port known' => [['app.test'], 'app.test', null, true],
    'upper-case host' => [['app.test'], 'APP.TEST', 443, true],
    'other host' => [['app.test'], 'evil.example', 443, false],
    'a subdomain' => [['app.test'], 'evil.app.test', 443, false],
    'a longer name' => [['app.test'], 'app.test.evil.example', 443, false],
    'an empty host (Symfony found it invalid)' => [['app.test'], '', 443, false],
    'entry port matches' => [['app.test:8443'], 'app.test', 8443, true],
    'entry port as a string' => [['app.test:8443'], 'app.test', '8443', true],
    'entry port differs' => [['app.test:8443'], 'app.test', 9999, false],
    'entry port, request port unknown' => [['app.test:8443'], 'app.test', null, false],
    'entry port, request port not a number' => [['app.test:8443'], 'app.test', 'x', false],
    'second entry' => [['other.test', 'app.test:8443'], 'app.test', 8443, true],
    'IPv6' => [['[::1]'], '[::1]', 8000, true],
    'no entries' => [[], 'app.test', 443, false],
]);

it('normalises an origin, or says it is not one', function (string $value, ?string $normalised) {
    expect(HostRules::normaliseOrigin($value))->toBe($normalised);
})->with([
    'plain' => ['https://console.example.com', 'https://console.example.com'],
    'upper case' => ['HTTPS://Console.Example.COM', 'https://console.example.com'],
    'spaces' => ['  https://console.example.com  ', 'https://console.example.com'],
    'trailing slash' => ['https://console.example.com/', 'https://console.example.com'],
    'https default port dropped' => ['https://console.example.com:443', 'https://console.example.com'],
    'http default port dropped' => ['http://localhost:80', 'http://localhost'],
    'other port kept' => ['http://localhost:8080', 'http://localhost:8080'],
    'the other scheme\'s default port kept' => ['https://console.example.com:80', 'https://console.example.com:80'],
    'IPv6' => ['http://[::1]:5173', 'http://[::1]:5173'],
    'another scheme' => ['chrome-extension://abcdefgh', 'chrome-extension://abcdefgh'],
    'null' => ['null', 'null'],
    'NULL with spaces' => [' NULL ', 'null'],
    'empty' => ['', null],
    'no scheme' => ['console.example.com', null],
    'a path' => ['https://console.example.com/app', null],
    'two slashes' => ['https://console.example.com//', null],
    'a query' => ['https://console.example.com?x=1', null],
    'user info' => ['https://user@console.example.com', null],
    'no host' => ['https://', null],
    'port 0' => ['https://console.example.com:0', null],
    'port 65536' => ['https://console.example.com:65536', null],
    'empty port' => ['https://console.example.com:', null],
    'a wildcard' => ['https://*.example.com', null],
    'javascript' => ['javascript:alert(1)', null],
]);

it('keeps only the valid origins, and none for a bad value', function (mixed $configured, array $origins) {
    expect(hostRulesFrom(['runtime-mcp.allowed_origins' => $configured])->origins())->toBe($origins);
})->with([
    'default' => [[], []],
    'normalised' => [['HTTPS://Console.Example.com/', 'null'], ['https://console.example.com', 'null']],
    'bad entries skipped' => [['https://a.test/path', 5, null, 'b.test', 'https://c.test'], ['https://c.test']],
    'a string' => ['https://console.example.com', []],
    'a map' => [['console' => 'https://console.example.com'], []],
    'null' => [null, []],
]);

it('matches an origin after both sides are normalised', function (array $configured, string $origin, bool $allowed) {
    expect(hostRulesFrom(['runtime-mcp.allowed_origins' => $configured])->allowsOrigin($origin))->toBe($allowed);
})->with([
    'exact' => [['https://console.example.com'], 'https://console.example.com', true],
    'case' => [['https://console.example.com'], 'HTTPS://CONSOLE.EXAMPLE.COM', true],
    'entry with the default port' => [['https://console.example.com:443'], 'https://console.example.com', true],
    'entry with a trailing slash' => [['https://console.example.com/'], 'https://console.example.com', true],
    'other origin' => [['https://console.example.com'], 'https://evil.example', false],
    'other scheme' => [['https://console.example.com'], 'http://console.example.com', false],
    'other port' => [['https://console.example.com'], 'https://console.example.com:8443', false],
    'a subdomain' => [['https://example.com'], 'https://evil.example.com', false],
    'a path in the header' => [['https://console.example.com'], 'https://console.example.com/x', false],
    'null not listed' => [['https://console.example.com'], 'null', false],
    'null listed' => [['null'], 'null', true],
    'empty header' => [['https://console.example.com'], '', false],
    'empty header, null listed' => [['null'], '', false],
    'nothing allowed (the default)' => [[], 'https://console.example.com', false],
]);
