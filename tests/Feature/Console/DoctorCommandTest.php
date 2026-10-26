<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use SinaSaeedi\RuntimeMcp\Console\DoctorCommand;
use SinaSaeedi\RuntimeMcp\Plugins\Core\CorePlugin;
use SinaSaeedi\RuntimeMcp\Server\RuntimeServer;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Plugins\FakeDoctorPlugin;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Plugins\FakeLeakyDoctorPlugin;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Plugins\FakePrivilegeDoctorPlugin;
use SinaSaeedi\RuntimeMcp\Tests\Support\BootCapture;
use SinaSaeedi\RuntimeMcp\Tests\Support\Users;
use SinaSaeedi\RuntimeMcp\Tests\TestCase;

/*
 * Spec 7.3, 01-12 Part C: the report, the footer and the exit code of php artisan runtime-mcp:doctor.
 * Artisan::call() writes to a plain BufferedOutput, so the colour tags are gone and the text can be
 * compared as it is. For "does not contain", use str_contains(): Pest's ->not->toContain($x, $message)
 * reads the message as a second needle (01-04 trap).
 */

/*
 * The test read-only connection (testing_readonly) is SQLite, so with the database or models plugin on,
 * the Read-only role check (01-13) fails. A test that counts lines or expects exit 0 turns both off; the
 * check then passes with "Not needed".
 */
const NO_DATABASE_PLUGINS = ['runtime-mcp.plugins.database' => false, 'runtime-mcp.plugins.models' => false];

/** @return array{int, string} the exit code and the whole output */
function callDoctorCommand(): array
{
    $code = Artisan::call(DoctorCommand::NAME);

    return [$code, Artisan::output()];
}

it('prints a healthy strict report: the header, a pass line per core check, exit 0', function () {
    currentTest()->bootWith(['runtime-mcp.enabled' => true, ...NO_DATABASE_PLUGINS], 'production');

    [$code, $output] = callDoctorCommand();
    $version = app(RuntimeServer::class)->version();

    expect($code)->toBe(0)
        ->and($output)->toContain("Runtime MCP doctor — Runtime Test (production), strict, package {$version}")
        ->and(substr_count($output, '✓'))->toBe(15)
        ->and(str_contains($output, '✗'))->toBeFalse()
        ->and(str_contains($output, '→'))->toBeFalse()
        ->and($output)->toContain('✓ Endpoint')
        ->and($output)->toContain('RUNTIME_MCP_ENABLED is true.')
        ->and($output)->toContain('Every boot rule passes.')
        ->and($output)->toContain('Statements time out after 5000 ms.')
        ->and($output)->toContain('Read tools run in a read-only transaction on 1 connection.')
        ->and($output)->toContain('Every check passes.')
        ->and(str_contains($output, '<fg='))->toBeFalse();
});

it('says relaxed in the header of a relaxed environment', function () {
    currentTest()->bootWith(['runtime-mcp.enabled' => true], 'local');

    expect(callDoctorCommand()[1])->toContain('Runtime MCP doctor — Runtime Test (local), relaxed, package ');
});

it('prints a failure with its fix on the next line, and exits 1', function () {
    currentTest()->bootWith(['runtime-mcp.enabled' => true, ...NO_DATABASE_PLUGINS, 'runtime-mcp.redaction.extra_patterns' => ['/ok/', '/(unclosed/']], 'production');

    [$code, $output] = callDoctorCommand();

    expect($code)->toBe(1)
        ->and($output)->toMatch('/✗ Redaction patterns\s+Every tool call fails until redaction\.extra_patterns entry 2 is fixed\.\s*\n    → Fix the regex; test it with preg_match\(\)\.\n/u')
        ->and($output)->toContain('1 failure. Fix them before the release goes live.');
});

it('exits 1 for a boot rule failure in a strict environment, and says the endpoint stays off', function () {
    currentTest()->bootWith(['runtime-mcp.enabled' => true, ...NO_DATABASE_PLUGINS, 'runtime-mcp.auth.api_keys' => null], 'production');

    [$code, $output] = callDoctorCommand();

    expect($code)->toBe(1)
        ->and($output)->toContain('✗ Boot rules')
        // A message with "<name>" is printed as text, not read as a console tag.
        ->and($output)->toContain('Create a key with `php artisan runtime-mcp:key <name>`')
        ->and($output)->toContain('1 failure. The endpoint stays off until every boot rule and route failure is fixed.');
});

it('runs every rule when the endpoint is off: a warning for the endpoint, and still exit 1', function () {
    currentTest()->bootWith(['runtime-mcp.enabled' => false, ...NO_DATABASE_PLUGINS, 'runtime-mcp.auth.api_keys' => null], 'production');

    [$code, $output] = callDoctorCommand();

    expect($code)->toBe(1)
        ->and($output)->toContain('! Endpoint')
        ->and($output)->toContain('    → Set RUNTIME_MCP_ENABLED=true when agents may connect. The other checks still run.')
        ->and($output)->toContain('✗ Boot rules')
        ->and($output)->toContain('1 failure, 1 warning.');
});

it('exits 0 with warnings only', function () {
    currentTest()->bootWith(['runtime-mcp.enabled' => true, ...NO_DATABASE_PLUGINS, 'runtime-mcp.plugins.horizon' => true], 'production');

    [$code, $output] = callDoctorCommand();

    expect($code)->toBe(0)
        ->and($output)->toContain('! Plugins')
        ->and($output)->toContain('No plugin named `horizon` in this version.')
        ->and($output)->toContain('1 warning.');
});

it('exits 0 in a relaxed environment with database.connection unset: the boot rule only warns', function () {
    currentTest()->bootWith(['runtime-mcp.enabled' => true, 'runtime-mcp.database.connection' => null], 'local');

    [$code, $output] = callDoctorCommand();

    expect($code)->toBe(0)
        ->and($output)->toContain('! Boot rules')
        ->and($output)->toContain('`database.connection`: RUNTIME_MCP_DB_CONNECTION is not set.');
});

it('warns, exit 0, for empty keys with a STDIO principal', function () {
    currentTest()->bootWith(['runtime-mcp.enabled' => true, ...NO_DATABASE_PLUGINS, 'runtime-mcp.auth.api_keys' => null, 'runtime-mcp.auth.stdio_principal' => 'local-dev'], 'production');

    [$code, $output] = callDoctorCommand();

    expect($code)->toBe(0)
        ->and($output)->toContain('RUNTIME_MCP_API_KEYS is empty, so the HTTP endpoint stays off. The STDIO server runs as `local-dev`.');
});

it('fails for a linked user that does not exist', function () {
    currentTest()->bootWith(['runtime-mcp.enabled' => true, 'runtime-mcp.auth.link_users' => [TestCase::TEST_KEY_NAME => 'nobody@example.com']], 'production');
    Users::create('someone@example.com');

    [$code, $output] = callDoctorCommand();

    expect($code)->toBe(1)
        ->and($output)->toContain('Key `tests` is linked to a user that does not exist');
});

it('fails for an empty host list and for a bad route', function (array $config, string $text) {
    /** @var array<string, mixed> $config */
    currentTest()->bootWith(['runtime-mcp.enabled' => true, ...$config], 'production');

    [$code, $output] = callDoctorCommand();

    expect($code)->toBe(1)->and($output)->toContain($text);
})->with([
    'no APP_URL' => [['app.url' => ''], 'Every request is refused.'],
    'a bad path' => [['runtime-mcp.path' => ''], 'runtime-mcp.path is not a non-empty string, so no MCP route is registered.'],
]);

it('counts plugin results like core results', function () {
    // The plugins value names only the fake list, or the base plugins left out of it are unknown keys.
    currentTest()->bootWith(['runtime-mcp.enabled' => true, 'runtime-mcp.plugins' => ['core' => true]], 'production', BootCapture::plugins([CorePlugin::class, FakeDoctorPlugin::class]));

    [$code, $output] = callDoctorCommand();

    expect($code)->toBe(1)
        ->and($output)->toContain('✓ Fake plugin check')
        ->and($output)->toContain('plugin pass line <info>kept</info>')
        ->and($output)->toContain('! Fake plugin check')
        ->and($output)->toContain('    → plugin warn fix')
        ->and($output)->toContain('✗ Fake plugin check')
        ->and($output)->toContain('    → plugin fail fix')
        ->and($output)->toContain('1 failure, 1 warning.');
});

it('fails a privilege check in a strict environment and only warns in a relaxed one (spec 4.6)', function (string $environment, int $code, string $glyph, string $footer) {
    currentTest()->bootWith(['runtime-mcp.enabled' => true, 'runtime-mcp.plugins' => ['core' => true]], $environment, BootCapture::plugins([CorePlugin::class, FakePrivilegeDoctorPlugin::class]));

    [$exit, $output] = callDoctorCommand();

    expect($exit)->toBe($code)
        ->and($output)->toContain("{$glyph} Fake privileges")
        ->and($output)->toContain('    → REVOKE INSERT ON orders')
        ->and($output)->toContain($footer);
})->with([
    'strict' => ['production', 1, '✗', '1 failure. Fix them before the release goes live.'],
    'relaxed' => ['local', 0, '!', '1 warning.'],
]);

it('never prints a key, a hash, the RUNTIME_MCP_API_KEYS value or a linked email', function () {
    $key = 'rmcp_'.str_repeat('Zq9', 14).'Z';
    $hash = hash('sha256', $key);
    $keys = "sina:{$hash},broken:{$hash}x";

    currentTest()->bootWith([
        'runtime-mcp.enabled' => true,
        'runtime-mcp.auth.api_keys' => $keys,
        'runtime-mcp.auth.link_users' => ['sina' => 'sina.secret@example.com', 'kate' => 'kate.secret@example.com'],
        'runtime-mcp.auth.stdio_principal' => $key,
    ], 'production');
    Users::create('sina.secret@example.com');

    [, $output] = callDoctorCommand();

    expect($output)->toContain('RUNTIME_MCP_API_KEYS entry 2 is not in the name:sha256hex format.')
        ->and($output)->toContain('Key `kate` is linked to a user that does not exist')
        ->and($output)->toContain('RUNTIME_MCP_STDIO_PRINCIPAL looks like an API key.');

    foreach ([$key, $hash, $keys, 'secret@example.com'] as $secret) {
        expect(str_contains($output, $secret))->toBeFalse("the output holds {$secret}");
    }
});

it('redacts a secret that a check message or fix holds by mistake', function () {
    currentTest()->bootWith(
        ['runtime-mcp.enabled' => true, 'runtime-mcp.plugins' => ['core' => true]],
        'production',
        BootCapture::plugins([CorePlugin::class, FakeLeakyDoctorPlugin::class]),
    );

    [, $output] = callDoctorCommand();

    expect($output)->toContain('the check found [redacted:')
        ->and($output)->toContain('    → remove [redacted:')
        ->and(str_contains($output, TestCase::TEST_KEY))->toBeFalse();
});

it('still redacts with the built-in patterns when an extra pattern is broken', function () {
    currentTest()->bootWith([
        'runtime-mcp.enabled' => true,
        'runtime-mcp.plugins' => ['core' => true],
        'runtime-mcp.redaction.extra_patterns' => ['/(unclosed/'],
    ], 'production', BootCapture::plugins([CorePlugin::class, FakeLeakyDoctorPlugin::class]));

    [$code, $output] = callDoctorCommand();

    expect($code)->toBe(1)
        ->and($output)->toContain('Every tool call fails until redaction.extra_patterns entry 1 is fixed.')
        ->and(str_contains($output, TestCase::TEST_KEY))->toBeFalse()
        ->and(config('runtime-mcp.redaction.extra_patterns'))->toBe(['/(unclosed/']);
});

it('is registered with a description', function () {
    currentTest()->bootWith();

    $command = Artisan::all()[DoctorCommand::NAME] ?? null;

    expect($command)->toBeInstanceOf(DoctorCommand::class)
        ->and($command?->getDescription())->toBe('Check the Runtime MCP config and print one report (exit code 1 on a failure)');
});
