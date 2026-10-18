<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Principal;
use SinaSaeedi\RuntimeMcp\Security\Auth\ApiKey;
use SinaSaeedi\RuntimeMcp\Security\Auth\AuthMethod;
use SinaSaeedi\RuntimeMcp\Security\Auth\CurrentPrincipal;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\TestCase;

/*
 * Rule 14 and spec 5.13. Plain test requests do not flush scoped instances, so these tests call
 * forgetScopedInstances(), which is what Octane does between requests.
 */

beforeEach(fn () => AuditLog::clear());
afterEach(fn () => AuditLog::clear());

it('binds CurrentPrincipal as scoped', function () {
    $holder = app(CurrentPrincipal::class);
    $holder->set(new Principal('sina', AuthMethod::ApiKey));

    expect(app(CurrentPrincipal::class))->toBe($holder);

    app()->forgetScopedInstances();

    expect(app(CurrentPrincipal::class))->not->toBe($holder)
        ->and(app(CurrentPrincipal::class)->get())->toBeNull();
});

it('does not carry a principal into the next request', function () {
    currentTest()->bootWith(['runtime-mcp.enabled' => true]);

    currentTest()->mcpPost('tools/list')->assertOk();
    expect(app(CurrentPrincipal::class)->get()?->name)->toBe(TestCase::TEST_KEY_NAME);

    app()->forgetScopedInstances();

    currentTest()->mcpPost('tools/list', key: null)->assertUnauthorized();
    expect(app(CurrentPrincipal::class)->get())->toBeNull();
});

it('gives each request in one worker its own principal', function () {
    $first = 'rmcp_'.str_repeat('One1', 10).'abc';
    $second = 'rmcp_'.str_repeat('Two2', 10).'abc';
    currentTest()->bootWith([
        'runtime-mcp.enabled' => true,
        'runtime-mcp.auth.api_keys' => 'alice:'.ApiKey::hash($first).',bob:'.ApiKey::hash($second),
    ]);

    currentTest()->mcpPost('tools/list', key: $first)->assertOk();
    expect(app(CurrentPrincipal::class)->get()?->name)->toBe('alice');

    app()->forgetScopedInstances();

    currentTest()->mcpPost('tools/list', key: $second)->assertOk();
    expect(app(CurrentPrincipal::class)->get()?->name)->toBe('bob');
});
