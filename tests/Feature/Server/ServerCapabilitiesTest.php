<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Tests\Support\ProtocolEra;

beforeEach(fn () => currentTest()->bootWith(['runtime-mcp.enabled' => true]));

it('advertises the tools and prompts capabilities only', function () {
    expect(currentTest()->mcpPost('initialize')->json('result.capabilities'))->toBe([
        'tools' => ['listChanged' => false],
        'prompts' => ['listChanged' => false],
    ]);
});

it('answers tools/list and prompts/list with empty lists', function (ProtocolEra $era) {
    expect(currentTest()->mcpPost('tools/list', era: $era)->assertOk()->json('result.tools'))->toBe([])
        ->and(currentTest()->mcpPost('prompts/list', era: $era)->assertOk()->json('result.prompts'))->toBe([]);
})->with([ProtocolEra::Legacy, ProtocolEra::Modern]);

it('answers ping', function () {
    currentTest()->mcpPost('ping')->assertOk()->assertJsonPath('jsonrpc', '2.0');
});

it('does not know the methods of rule 19', function (string $method) {
    currentTest()->mcpPost($method)->assertNotFound()->assertJsonPath('error.code', -32601);
})->with(['resources/list', 'resources/read', 'resources/templates/list', 'completion/complete', 'subscriptions/listen']);

it('refuses a 2026-07-28 request without the Mcp-Method header', function () {
    currentTest()->mcpPost('tools/list', headers: ['Mcp-Method' => ''], era: ProtocolEra::Modern)
        ->assertStatus(400)->assertJsonPath('error.code', -32020);
});

it('accepts protocol version 2025-03-26 through initialize (laravel/mcp 1.1.0)', function () {
    currentTest()->mcpPost('initialize', ['protocolVersion' => '2025-03-26'])
        ->assertOk()->assertJsonPath('result.protocolVersion', '2025-03-26');

    expect(currentTest()->mcpPost('tools/list', headers: ['MCP-Protocol-Version' => '2025-03-26'])->assertOk()->json('result.tools'))->toBe([]);
});

it('refuses protocol version 2024-11-05', function () {
    currentTest()->mcpPost('tools/list', headers: ['MCP-Protocol-Version' => '2024-11-05'])
        ->assertStatus(400)->assertJsonPath('error.code', -32022);
});
