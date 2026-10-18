<?php

declare(strict_types=1);

use Laravel\Mcp\Server\Tools\ExecuteTools;
use Laravel\Mcp\Server\Tools\ToolSearch;
use SinaSaeedi\RuntimeMcp\Principal;
use SinaSaeedi\RuntimeMcp\Security\Auth\AuthMethod;
use SinaSaeedi\RuntimeMcp\Security\Auth\CurrentPrincipal;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Servers\CatalogTestServer;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\Gates;
use SinaSaeedi\RuntimeMcp\Tests\TestCase;

/*
 * Rule 3, spec 3.2: execute_tools runs many tools in one tools/call and calls ToolInvoker for each.
 * An HTTP middleware sees one request; the pipeline sees every inner call. laravel/mcp's test API
 * runs the server without HTTP (spec 3.2 "Testing"), so the test binds the principal itself.
 */

beforeEach(function () {
    AuditLog::clear();
    Gates::allowFixtureTools(); // default deny (01-09)
});
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

it('refuses the first inner call without a principal before the pipeline, and the batch stops there', function () {
    // Since 01-09, shouldRegister() asks the Gate, and with no principal it hides every tool.
    // ToolSearch::invokeTool() checks eligibleForRegistration() before ToolInvoker (ToolSearch.php:
    // 191-192), so laravel/mcp refuses the call and our pipeline never sees it: no audit entry.
    // The package never registers ToolSearch in v1.0.0; 01-40 audits this path. The pipeline's own
    // no_principal denial is proved without the catalog (DirectInvokerTest I1).
    CatalogTestServer::tool(executeToolsByName(), executeToolsTwoCalls())
        ->assertHasErrors()
        ->assertSee('Tool [echo] is not available.');

    // execute_tools stops at the first result with isError (ToolSearch.php:142-144, spec 5.17).
    expect(AuditLog::entries())->toBe([]);
});
