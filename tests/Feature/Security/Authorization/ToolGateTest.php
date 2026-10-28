<?php

declare(strict_types=1);

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Psr\Log\LoggerInterface;
use SinaSaeedi\RuntimeMcp\Principal;
use SinaSaeedi\RuntimeMcp\Security\Auth\AuthMethod;
use SinaSaeedi\RuntimeMcp\Security\Authorization\GateDecision;
use SinaSaeedi\RuntimeMcp\Security\Authorization\ToolGate;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\EchoTool;
use SinaSaeedi\RuntimeMcp\Tests\Support\RecordingLogger;

/*
 * Spec 5.5: default deny. ToolGate::decide() directly, for every Gate case of the story's edge-case
 * table. The subject is the linked User, or the Principal itself (01-06).
 */

function toolGateDecide(?Principal $principal): GateDecision
{
    return app(ToolGate::class)->decide($principal, new EchoTool);
}

function toolGateKey(string $name = 'ops'): Principal
{
    return new Principal($name, AuthMethod::ApiKey);
}

function toolGateLinkedKey(): Principal
{
    return new Principal('sina', AuthMethod::ApiKey, (new User)->forceFill(['id' => 7]));
}

it('denies no_principal without asking the Gate', function () {
    $asked = false;
    Gate::define('runtime-mcp.echo', function () use (&$asked): bool {
        $asked = true;

        return true;
    });

    $decision = toolGateDecide(null);

    expect($decision->allowed)->toBeFalse()
        ->and($decision->reason?->value)->toBe('no_principal')
        ->and($asked)->toBeFalse();
});

it('denies no_gate when the app defines no Gate for the tool', function () {
    Gate::define('runtime-mcp.second_echo', fn (): bool => true); // another tool's Gate does not count

    $decision = toolGateDecide(toolGateKey());

    expect($decision->allowed)->toBeFalse()
        ->and($decision->reason?->value)->toBe('no_gate')
        ->and($decision->message)->toBeNull();
});

it('still denies no_gate when a Gate::before() hook allows everything', function () {
    Gate::before(fn (): bool => true);

    expect(toolGateDecide(toolGateKey())->reason?->value)->toBe('no_gate');
});

it('keeps the app\'s own before() result for a defined Gate', function () {
    Gate::define('runtime-mcp.echo', fn (): bool => false);
    Gate::before(fn (): bool => true);

    expect(toolGateDecide(toolGateKey())->allowed)->toBeTrue();
});

it('denies gate_error, and writes one warning, when a Gate typed User meets an unlinked key', function () {
    $log = RecordingLogger::install();
    Gate::define('runtime-mcp.echo', fn (User $user): bool => true);

    $decision = toolGateDecide(toolGateKey());

    expect($decision->allowed)->toBeFalse()
        ->and($decision->reason?->value)->toBe('gate_error')
        ->and($decision->message)->toBeNull()
        ->and($log->messages())->toBe([
            'runtime-mcp: the Gate runtime-mcp.echo (or a Gate::before() or Gate::after() callback, or a GateEvaluated listener) threw TypeError for a Principal subject. Type the callback as Principal|User.',
        ]);
});

it('allows the same Gate for a key linked to a user', function () {
    $log = RecordingLogger::install();
    Gate::define('runtime-mcp.echo', fn (User $user): bool => $user->getAuthIdentifier() === 7);

    expect(toolGateDecide(toolGateLinkedKey())->allowed)->toBeTrue()
        ->and($log->records)->toBe([]);
});

it('runs a Principal|User Gate for the key name', function () {
    Gate::define('runtime-mcp.echo', fn (Principal|User $who): bool => $who instanceof Principal && $who->name === 'sina');

    $ops = toolGateDecide(toolGateKey('ops'));

    expect(toolGateDecide(toolGateKey('sina'))->allowed)->toBeTrue()
        ->and($ops->allowed)->toBeFalse()
        ->and($ops->reason?->value)->toBe('gate_denied');
});

it('keeps the Gate\'s own denial message', function () {
    Gate::define('runtime-mcp.echo', fn (): Response => Response::deny('Ask the platform team'));

    $decision = toolGateDecide(toolGateKey());

    expect($decision->reason?->value)->toBe('gate_denied')
        ->and($decision->message)->toBe('Ask the platform team');
});

it('gives no message for a plain false', function () {
    Gate::define('runtime-mcp.echo', fn (): bool => false);

    $decision = toolGateDecide(toolGateKey());

    expect($decision->reason?->value)->toBe('gate_denied')
        ->and($decision->message)->toBeNull();
});

it('asks the Gate again on every call, and caches nothing', function () {
    $open = true;
    Gate::define('runtime-mcp.echo', function () use (&$open): bool {
        return $open;
    });

    $first = toolGateDecide(toolGateKey())->allowed;
    $open = false;

    expect($first)->toBeTrue()
        ->and(toolGateDecide(toolGateKey())->allowed)->toBeFalse();
});

it('still denies gate_error when the log itself throws', function () {
    app()->instance(LoggerInterface::class, new class extends Psr\Log\AbstractLogger
    {
        public function log($level, string|Stringable $message, array $context = []): void
        {
            throw new RuntimeException('The log disk is full.');
        }
    });
    Gate::define('runtime-mcp.echo', fn (User $user): bool => true);

    expect(toolGateDecide(toolGateKey())->reason?->value)->toBe('gate_error');
});
