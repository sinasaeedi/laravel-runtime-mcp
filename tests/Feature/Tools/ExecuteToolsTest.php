<?php

declare(strict_types=1);

use Laravel\Mcp\Server\Tools\ExecuteTools;
use Laravel\Mcp\Server\Tools\ToolSearch;
use SinaSaeedi\RuntimeMcp\Principal;
use SinaSaeedi\RuntimeMcp\Security\Auth\AuthMethod;
use SinaSaeedi\RuntimeMcp\Security\Auth\CurrentPrincipal;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Servers\CatalogTestServer;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\TestCase;

/*
 * Rule 3, spec 3.2: execute_tools runs many tools in one tools/call and calls ToolInvoker for each.
 * An HTTP middleware sees one request; the pipeline sees every inner call. laravel/mcp's test API
 * runs the server without HTTP (spec 3.2 "Testing"), so the test binds the principal itself.
 */

beforeEach(fn () => AuditLog::clear());
afterEach(fn () => AuditLog::clear());

/** The execute_tools meta-tool, found on the server by its name. */
function executeToolsByName(): ExecuteTools
{
    return (new ToolSearch([]))->tools()[1];
}

/** @return array<string, mixed> */
function executeToolsTwoCalls(): array
{
    return ['calls' => [
        ['name' => 'echo', 'arguments' => ['text' => 'a']],
        ['name' => 'second_echo', 'arguments' => ['text' => 'b']],
    ]];
}

it('runs the pipeline and writes one audit entry for each inner call', function () {
    app(CurrentPrincipal::class)->set(new Principal(TestCase::TEST_KEY_NAME, AuthMethod::ApiKey));

    CatalogTestServer::tool(executeToolsByName(), executeToolsTwoCalls())
        ->assertHasNoErrors()
        ->assertSee(['"status":"ok"', '"data":{"text":"a"}', '"data":{"text":"b"}']);

    $entries = AuditLog::entries();

    expect($entries)->toHaveCount(2)
        ->and(array_column($entries, 'tool'))->toBe(['echo', 'second_echo'])
        ->and(array_column($entries, 'params'))->toBe([['text' => 'a'], ['text' => 'b']])
        ->and(array_column($entries, 'principal'))->toBe(['tests', 'tests'])
        ->and(array_column($entries, 'outcome'))->toBe(['success', 'success']);
});

it('drops the structured text copy inside the batch, and keeps the label', function () {
    app(CurrentPrincipal::class)->set(new Principal(TestCase::TEST_KEY_NAME, AuthMethod::ApiKey));

    // The outer text holds each inner result once: as structuredContent, never as structured text.
    CatalogTestServer::tool(executeToolsByName(), executeToolsTwoCalls())
        ->assertSee('Result from Runtime Test (testing).')
        ->assertDontSee('\"environment\":\"testing\",\"status\":\"ok\"');
});

it('denies the first inner call without a principal, and the batch stops there', function () {
    CatalogTestServer::tool(executeToolsByName(), executeToolsTwoCalls())
        ->assertHasErrors()
        ->assertSee('No authenticated principal.');

    $entries = AuditLog::entries();

    // execute_tools stops at the first result with isError (ToolSearch.php:142-144, spec 5.17).
    expect($entries)->toHaveCount(1)
        ->and($entries[0]['tool'])->toBe('echo')
        ->and($entries[0]['decision'])->toBe('denied')
        ->and($entries[0]['reason'])->toBe('no_principal')
        ->and($entries[0]['principal'])->toBeNull();
});
