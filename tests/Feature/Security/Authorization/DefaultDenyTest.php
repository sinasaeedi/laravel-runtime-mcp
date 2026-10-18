<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use SinaSaeedi\RuntimeMcp\Principal;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\FixtureTools;
use SinaSaeedi\RuntimeMcp\Tests\Support\ProtocolEra;
use SinaSaeedi\RuntimeMcp\Tests\Support\RecordingLogger;
use SinaSaeedi\RuntimeMcp\Tests\Support\Users;
use SinaSaeedi\RuntimeMcp\Tests\TestCase;

/*
 * Spec 11.2 "Authorization": default deny, and denied tools hidden in tools/list. Over HTTP with the
 * default test key (principal "tests") and the 01-08 fixture tools, with no Gate unless a test
 * defines one. tools/list is laravel/mcp's ListTools, which filters with shouldRegister().
 */

beforeEach(fn () => AuditLog::clear());
afterEach(fn () => AuditLog::clear());

/** @return list<string> the tool names that tools/list shows */
function defaultDenyListed(ProtocolEra $era = ProtocolEra::Legacy): array
{
    $names = currentTest()->mcpPost('tools/list', era: $era)->assertOk()->json('result.tools.*.name');
    assert(is_array($names));

    return array_values(array_map(fn (mixed $name): string => is_string($name) ? $name : '', $names));
}

it('lists nothing for a valid key while the app defines no Gate', function (ProtocolEra $era) {
    FixtureTools::serve(gates: false);

    expect(defaultDenyListed($era))->toBe([]);
})->with([ProtocolEra::Legacy, ProtocolEra::Modern]);

it('lists exactly the tools whose Gate allows the principal', function (ProtocolEra $era) {
    FixtureTools::serve(gates: false);
    Gate::define('runtime-mcp.echo', fn (Principal|User $who): bool => $who instanceof Principal && $who->name === 'tests');
    Gate::define('runtime-mcp.secret', fn (): bool => false);

    expect(defaultDenyListed($era))->toBe(['echo']);
})->with([ProtocolEra::Legacy, ProtocolEra::Modern]);

it('lists nothing when a Gate::before() hook allows everything but no Gate is defined', function () {
    FixtureTools::serve(gates: false);
    Gate::before(fn (): bool => true);

    expect(defaultDenyListed())->toBe([]);
});

it('hides a tool whose Gate is typed User from an unlinked key, and shows it to a linked key', function () {
    FixtureTools::serve(gates: false);
    $log = RecordingLogger::install();
    Gate::define('runtime-mcp.echo', fn (User $user): bool => true);

    expect(defaultDenyListed())->toBe([])
        ->and($log->messages())->toHaveCount(1);

    FixtureTools::serve(['runtime-mcp.auth.link_users' => [TestCase::TEST_KEY_NAME => 'tests@example.com']], gates: false);
    Users::create('tests@example.com');
    Gate::define('runtime-mcp.echo', fn (User $user): bool => $user->getAttribute('email') === 'tests@example.com');

    expect(defaultDenyListed())->toBe(['echo']);
});

it('asks the Gate again for every list', function () {
    FixtureTools::serve(gates: false);
    $open = false;
    Gate::define('runtime-mcp.echo', function () use (&$open): bool {
        return $open;
    });

    $before = defaultDenyListed();
    $open = true;

    expect($before)->toBe([])
        ->and(defaultDenyListed())->toBe(['echo']);
});
