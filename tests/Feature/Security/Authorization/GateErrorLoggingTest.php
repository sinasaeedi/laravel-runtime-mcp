<?php

declare(strict_types=1);

use Illuminate\Auth\Access\Events\GateEvaluated;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use SinaSaeedi\RuntimeMcp\Principal;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\FixtureTools;
use SinaSaeedi\RuntimeMcp\Tests\Support\RecordingLogger;
use SinaSaeedi\RuntimeMcp\Tests\Support\Users;
use SinaSaeedi\RuntimeMcp\Tests\TestCase;

/*
 * Principle 1.4.3: a Gate that throws denies (gate_error). The operator gets one warning in the
 * app's default log per decision: the check, the exception class and the subject class, and how to
 * fix it. Never the exception message, a trace or the arguments.
 */

beforeEach(fn () => AuditLog::clear());
afterEach(function () {
    AuditLog::clear();

    foreach (AuditLog::files('gate-warnings*.log') as $file) {
        unlink($file);
    }
});

it('writes one warning per call, with the ability and the exception class, and no trace', function () {
    FixtureTools::serve(gates: false);
    $log = RecordingLogger::install();
    Gate::define('runtime-mcp.echo', fn (User $user): bool => true);

    FixtureTools::call('echo', ['text' => 'hi']);

    expect($log->records)->toBe([[
        'level' => 'warning',
        'message' => 'runtime-mcp: the Gate runtime-mcp.echo (or a Gate::before() or Gate::after() callback, or a GateEvaluated listener) threw TypeError for a Principal subject. Type the callback as Principal|User.',
        'context' => [],
    ]]);
});

it('names the User subject of a linked key', function () {
    FixtureTools::serve(['runtime-mcp.auth.link_users' => [TestCase::TEST_KEY_NAME => 'tests@example.com']], gates: false);
    Users::create('tests@example.com');
    $log = RecordingLogger::install();
    Gate::define('runtime-mcp.echo', fn (Principal $principal): bool => true);

    FixtureTools::call('echo', ['text' => 'hi']);

    expect($log->messages())->toBe([
        'runtime-mcp: the Gate runtime-mcp.echo (or a Gate::before() or Gate::after() callback, or a GateEvaluated listener) threw TypeError for a User subject. Type the callback as Principal|User.',
    ]);
});

it('denies and warns when a Gate::before() callback typed User throws', function () {
    FixtureTools::serve(gates: false);
    $log = RecordingLogger::install();
    Gate::define('runtime-mcp.echo', fn (): bool => true);
    Gate::before(fn (User $user): ?bool => null);

    $response = FixtureTools::call('echo', ['text' => 'hi']);

    expect(FixtureTools::envelope($response)['status'])->toBe('unavailable')
        ->and(AuditLog::entries()[0]['reason'] ?? null)->toBe('gate_error')
        ->and($log->messages())->toHaveCount(1);
});

it('writes to the app\'s default log channel', function () {
    FixtureTools::serve([
        'logging.default' => 'gate-warnings',
        'logging.channels.gate-warnings' => ['driver' => 'single', 'path' => storage_path('logs/gate-warnings.log')],
    ], gates: false);
    Gate::define('runtime-mcp.echo', fn (User $user): bool => true);

    FixtureTools::call('echo', ['text' => 'hi']);

    $lines = AuditLog::lines('gate-warnings.log');

    expect($lines)->toHaveCount(1)
        ->and(str_contains($lines[0], '.WARNING: runtime-mcp: the Gate runtime-mcp.echo (or a Gate::before() or Gate::after() callback, or a GateEvaluated listener) threw TypeError'))->toBeTrue($lines[0])
        ->and(str_contains($lines[0], 'must be of type'))->toBeFalse('The warning holds the exception message.')
        ->and(str_contains($lines[0], '#0'))->toBeFalse('The warning holds a trace.');
});

it('denies and names the after() hooks and the listeners when one of them throws', function (string $hook, string $error) {
    FixtureTools::serve(gates: false);
    $log = RecordingLogger::install();
    Gate::define('runtime-mcp.echo', fn (): bool => true);

    if ($hook === 'after') {
        Gate::after(fn (User $user): ?bool => null);
    } else {
        Event::listen(GateEvaluated::class, fn (GateEvaluated $event): mixed => $event->user?->getAuthIdentifier());
    }

    $response = FixtureTools::call('echo', ['text' => 'hi']);

    expect(FixtureTools::envelope($response)['status'])->toBe('unavailable')
        ->and(AuditLog::entries()[0]['reason'] ?? null)->toBe('gate_error')
        ->and($log->messages())->toBe([
            "runtime-mcp: the Gate runtime-mcp.echo (or a Gate::before() or Gate::after() callback, or a GateEvaluated listener) threw {$error} for a Principal subject. Type the callback as Principal|User.",
        ]);
})->with([
    'a Gate::after() callback typed User' => ['after', 'TypeError'],
    'a GateEvaluated listener that reads the user as a User' => ['listener', 'Error'],
]);
