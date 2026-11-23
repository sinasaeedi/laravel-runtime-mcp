<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use SinaSaeedi\RuntimeMcp\Principal;
use SinaSaeedi\RuntimeMcp\Testing\RuntimeMcp;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Prompts\EchoPrompt;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\EchoTool;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\Users;

/*
 * 01-39, spec 5.7: a kit call writes the real audit entry, to the real audit channel, with auth_method testing, so a test
 * call never looks like a real key or token. The kit reads the same entries through the Tool* events (01-05).
 */

beforeEach(function () {
    AuditLog::clear();
    config(['runtime-mcp.tools' => [EchoTool::class], 'runtime-mcp.prompts' => [EchoPrompt::class]]);
    Gate::define('runtime-mcp.echo', fn (): bool => true);
});

afterEach(fn () => AuditLog::clear());

it('TKA1 writes one audit entry per call, with auth_method testing and the test request\'s IP', function () {
    RuntimeMcp::test()->as('sina')->call('echo', ['text' => 'hi'])->assertAudited('allowed');

    $entries = AuditLog::entries();

    expect($entries)->toHaveCount(1)
        ->and($entries[0])->toMatchArray([
            'kind' => 'tool_call', 'environment' => 'testing', 'principal' => 'sina', 'user_id' => null,
            'auth_method' => 'testing', 'ip' => '127.0.0.1', 'client' => null, 'tool' => 'echo',
            'params' => ['text' => 'hi'], 'decision' => 'allowed', 'reason' => null, 'outcome' => 'success',
        ]);
});

it('TKA2 audits an asUser() call as user-<id>, with the user id', function () {
    $user = Users::create('ops@example.com');
    $id = $user->getAuthIdentifier();
    assert(is_int($id));

    RuntimeMcp::test()->asUser($user)->call('echo', ['text' => 'hi'])->assertAudited('allowed');

    expect(AuditLog::entries()[0] ?? [])->toMatchArray(['principal' => Principal::userName($id), 'user_id' => $id, 'auth_method' => 'testing']);
});

it('TKA3 audits a kit prompts/get with auth_method testing', function () {
    RuntimeMcp::test()->as('sina')->prompt('echo_prompt', ['mode' => 'quick'])->assertSee('Mode quick');
    RuntimeMcp::test()->as('sina')->prompt('no_such_prompt')->assertHidden();

    expect(AuditLog::entries())->toHaveCount(2)
        ->and(array_column(AuditLog::entries(), 'kind'))->toBe(['prompt_get', 'prompt_get'])
        ->and(array_column(AuditLog::entries(), 'auth_method'))->toBe(['testing', 'testing'])
        ->and(array_column(AuditLog::entries(), 'reason'))->toBe([null, 'unknown_prompt']);
});

it('TKA4 gives each result only the entries of its own call', function () {
    $chain = RuntimeMcp::test()->as('sina');

    $first = $chain->call('echo', ['text' => 'a']);
    $second = $chain->call('no_such_tool');

    $first->assertAudited('allowed');
    $second->assertAudited('denied')->assertDenied('unknown_tool');

    expect(AuditLog::entries())->toHaveCount(2);
});
