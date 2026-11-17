<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use SinaSaeedi\RuntimeMcp\Console\KeyCommand;
use SinaSaeedi\RuntimeMcp\Console\TokenCommand;
use SinaSaeedi\RuntimeMcp\Security\Auth\ApiKey;
use SinaSaeedi\RuntimeMcp\Security\Auth\ClientSetup;
use SinaSaeedi\RuntimeMcp\Security\Auth\KeyIssuer;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\CommandTrail;
use SinaSaeedi\RuntimeMcp\Tests\TestCase;

/*
 * Spec 5.3, 7.2 (01-27): php artisan runtime-mcp:key <name>. Artisan::output() is the plain text, tags stripped.
 * For "does not contain", use str_contains(): Pest's ->not->toContain($x, $message) reads the message as a second
 * needle (01-04 trap). Helpers start with "keyCommand".
 */

/** @return array{int, string} the exit code and the whole output */
function keyCommandRun(string $name): array
{
    $code = Artisan::call(KeyCommand::NAME, ['name' => $name]);

    return [$code, Artisan::output()];
}

/** The one key in the output. */
function keyCommandKey(string $output): string
{
    expect(preg_match_all('/rmcp_[A-Za-z0-9_-]{43}(?![A-Za-z0-9_-])/', $output, $matches))->toBeGreaterThan(0);
    $keys = array_values(array_unique($matches[0]));
    expect($keys)->toHaveCount(1);

    return $keys[0];
}

function keyCommandPair(string $name, string $label): string
{
    return $name.':'.ApiKey::hash(ApiKey::PREFIX.str_pad($label, 43, 'x'));
}

beforeEach(fn () => AuditLog::clear());
afterEach(fn () => AuditLog::clear());

it('KC1 prints the key once, the .env value, the client command, a Gate example and the inspector hint, in this order', function () {
    currentTest()->bootWith([
        'runtime-mcp.enabled' => true,
        'runtime-mcp.auth.api_keys' => keyCommandPair('ops', 'a'),
        'app.url' => 'https://staging.example.com',
    ], 'staging');

    [$code, $output] = keyCommandRun('sina');
    $key = keyCommandKey($output);
    $pair = 'sina:'.hash('sha256', $key);

    expect($code)->toBe(0)->and($output)->toBe(implode("\n", [
        '',
        ' Key for "sina" on Runtime Test (staging) — shown once. Store it in your MCP client or password manager.',
        '',
        "   {$key}",
        '',
        " 1. Set this in the staging server's .env (the server keeps only the hash):",
        '',
        '   RUNTIME_MCP_API_KEYS='.keyCommandPair('ops', 'a').",{$pair}",
        '',
        '   If you use config:cache, run it again after you edit .env.',
        '',
        ' 2. Add the server to Claude Code:',
        '',
        "   claude mcp add --transport http runtime-test-staging https://staging.example.com/mcp --header \"Authorization: Bearer {$key}\"",
        '',
        ' 3. Define a Gate for each tool this key may use, for example in AppServiceProvider::boot():',
        '',
        "   Gate::define('runtime-mcp.app_info', fn (Principal|User \$who) => true); // Principal: SinaSaeedi\\RuntimeMcp\\Principal",
        '',
        ' 4. Optional: check the endpoint from your own machine with the MCP Inspector:',
        '',
        '   npx @modelcontextprotocol/inspector',
        "   → Transport: Streamable HTTP · URL: https://staging.example.com/mcp · Header: Authorization: Bearer {$key}",
        '',
        '',
    ]));
});

it('KC2 prints a key that authenticates once its .env value is set, next to the keys that were there', function () {
    currentTest()->bootWith(['runtime-mcp.auth.api_keys' => TestCase::defaultKeyEntry()]);

    [, $output] = keyCommandRun('sina');
    $key = keyCommandKey($output);
    expect(preg_match('/^   RUNTIME_MCP_API_KEYS=(\S+)$/m', $output, $match))->toBe(1);
    $value = $match[1] ?? '';

    expect($value)->toBe(TestCase::defaultKeyEntry().',sina:'.ApiKey::hash($key));

    currentTest()->bootWith(['runtime-mcp.enabled' => true, 'runtime-mcp.auth.api_keys' => $value]);

    currentTest()->mcpPost('tools/list', key: $key)->assertOk();
    currentTest()->mcpPost('tools/list')->assertOk(); // the key that was there still works
    currentTest()->mcpPost('tools/list', key: ApiKey::generate())->assertUnauthorized();
});

it('KC3 refuses a name outside the name rule with exit 1, and prints no key', function (string $name, string $message) {
    [$code, $output] = keyCommandRun($name);

    expect($code)->toBe(1)
        ->and($output)->toContain($message)
        ->and(preg_match('/rmcp_[A-Za-z0-9_-]{43}/', $output))->toBe(0)
        ->and(str_contains($output, 'RUNTIME_MCP_API_KEYS'))->toBeFalse();
})->with([
    'a space' => ['sina saeedi', KeyIssuer::NAME_RULE],
    'a colon' => ['a:b', KeyIssuer::NAME_RULE],
    'the key prefix' => ['rmcp_bot', KeyIssuer::PREFIX_RULE],
    'the key prefix, upper case' => ['RMCP_bot', KeyIssuer::PREFIX_RULE],
]);

it('KC4 refuses with exit 1 and no key when the driver is not api_key', function (mixed $driver, string $message) {
    config(['runtime-mcp.auth.driver' => $driver]); // after boot: the boot rule would throw in testing

    [$code, $output] = keyCommandRun('sina');

    expect($code)->toBe(1)
        ->and($output)->toContain($message)
        ->and(preg_match('/rmcp_[A-Za-z0-9_-]{43}/', $output))->toBe(0);
})->with([
    'sanctum' => ['sanctum', 'The auth driver is `sanctum`. Use php artisan runtime-mcp:token <email>, or set the driver to `api_key` first.'],
    'another name' => ['passport', KeyCommand::OTHER_DRIVER],
    'not a string' => [null, KeyCommand::OTHER_DRIVER],
]);

it('KC5 never echoes a malformed current value, and prints the new pair alone with a warning', function () {
    $pasted = 'rmcp_PastedKeyPastedKeyPastedKeyPastedKey12345';
    config(['runtime-mcp.auth.api_keys' => keyCommandPair('ops', 'a').",{$pasted}"]);

    [$code, $output] = keyCommandRun('sina');
    $key = keyCommandKey($output);

    expect($code)->toBe(0)
        ->and($output)->toContain('   RUNTIME_MCP_API_KEYS=sina:'.ApiKey::hash($key)."\n")
        ->and($output)->toContain('The current RUNTIME_MCP_API_KEYS value is malformed (entry 2). Fix it, then append this pair.')
        ->and(str_contains($output, $pasted))->toBeFalse()
        ->and(str_contains($output, keyCommandPair('ops', 'a')))->toBeFalse();
});

it('KC6 warns about a second key with the same name, without a hash in the warning', function () {
    config(['runtime-mcp.auth.api_keys' => keyCommandPair('sina', 'a')]);

    [$code, $output] = keyCommandRun('sina');
    $warning = 'A key named "sina" already exists. This adds a second key for rotation; remove the old pair once the new key works.';

    expect($code)->toBe(0)
        ->and($output)->toContain('   RUNTIME_MCP_API_KEYS='.keyCommandPair('sina', 'a').',sina:')
        ->and($output)->toContain($warning)
        ->and(substr_count($output, keyCommandPair('sina', 'a')))->toBe(1);
});

it('KC7 prints a placeholder URL in quotes and the warnings when APP_URL is empty and the endpoint is off', function () {
    currentTest()->bootWith(['app.url' => ''], 'production');

    [$code, $output] = keyCommandRun('sina');
    $key = keyCommandKey($output);

    expect($code)->toBe(0)
        ->and($output)->toContain("   claude mcp add --transport http runtime-test-production 'https://<your-host>/mcp' --header \"Authorization: Bearer {$key}\"")
        ->and($output)->toContain('URL: https://<your-host>/mcp · Header')
        ->and($output)->toContain(ClientSetup::APP_URL_WARNING)
        ->and($output)->toContain(ClientSetup::ENDPOINT_WARNING)
        ->and($output)->toContain(" 1. Set this in the production server's .env");
});

it('KC8 prints the route path of runtime-mcp.path', function () {
    config(['runtime-mcp.path' => 'internal/mcp']);

    expect(keyCommandRun('sina')[1])->toContain(' https://app.test/internal/mcp --header ');
});

it('KC9 prints a style tag in APP_NAME as text', function () {
    config(['app.name' => '<fg=red>Shop</>']);

    expect(keyCommandRun('sina')[1])->toContain(' Key for "sina" on <fg=red>Shop</> (testing) — shown once.')
        ->toContain(' fgredshop-testing ');
});

it('KC10 writes no file, logs nothing, and no event holds the key', function () {
    $trail = CommandTrail::start();

    [$code, $output] = keyCommandRun('sina');
    $key = keyCommandKey($output);

    expect($code)->toBe(0)
        ->and($trail->texts)->toContain('Illuminate\Console\Events\CommandFinished')
        ->and($trail->eventsHolding($key))->toBe([])
        ->and($trail->eventsHolding(substr($key, 5)))->toBe([])
        ->and($trail->logged)->toBe([])
        ->and($trail->changedFiles())->toBe([])
        ->and(AuditLog::files())->toBe([]);
});

it('KC11 is registered next to doctor, with the token command', function () {
    $commands = Artisan::all();

    expect($commands)->toHaveKey(KeyCommand::NAME)
        ->and($commands[KeyCommand::NAME])->toBeInstanceOf(KeyCommand::class)
        ->and($commands)->toHaveKey(TokenCommand::NAME)
        ->and($commands[TokenCommand::NAME])->toBeInstanceOf(TokenCommand::class);
});
