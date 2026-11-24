<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Exceptions;
use SinaSaeedi\RuntimeMcp\Audit\AuditUnavailable;
use SinaSaeedi\RuntimeMcp\Mcp\McpToolAdapter;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\EchoTool;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\CatalogCalls;
use SinaSaeedi\RuntimeMcp\Tests\Support\ProtocolEra;

/*
 * 01-40 Part A, B, spec 3.2, 5.17: with catalog.enabled = true, tools/list shows only laravel/mcp's two meta-tools, and
 * search_tools lists only the tools the Gate allows, with our names, schemas and annotations. The outer search_tools
 * call is audited by our tools/call handler.
 */

beforeEach(fn () => AuditLog::clear());
afterEach(fn () => AuditLog::clear());

it('CL1 lists only search_tools and execute_tools with the catalog on', function (ProtocolEra $era) {
    CatalogCalls::serve();

    expect(CatalogCalls::listed($era))->toBe(['search_tools', 'execute_tools']);
})->with([ProtocolEra::Legacy, ProtocolEra::Modern]);

it('CL2 lists every allowed tool, and no meta-tool, with the catalog off or set to a value that is not true', function (mixed $value) {
    CatalogCalls::serve(['runtime-mcp.catalog.enabled' => $value]);

    expect(CatalogCalls::listed())->toBe(['echo', 'second_echo', 'throwing', 'big_result']);
})->with(['false' => [false], 'null' => [null], 'the string yes' => ['yes'], 'the string true' => ['true'], 'one' => [1]]);

it('CL3 finds only Gate-allowed tools, with our name, description, input schema and annotations', function (ProtocolEra $era) {
    CatalogCalls::serve(allow: ['echo']);

    $batch = CatalogCalls::batch(CatalogCalls::messages(CatalogCalls::call('search_tools', ['query' => 'echo'], $era)));
    $adapter = (new McpToolAdapter(new EchoTool, app()))->toArray();

    expect($batch)->toBe(['ok' => true, 'tools' => [[
        'name' => 'echo',
        'description' => $adapter['description'],
        'inputSchema' => $adapter['inputSchema'],
        'annotations' => ['readOnlyHint' => true, 'openWorldHint' => false],
    ]], 'hasMore' => false])
        ->and($adapter['description'])->toEndWith(McpToolAdapter::UNTRUSTED);
})->with([ProtocolEra::Legacy, ProtocolEra::Modern]);

it('CL4 answers search_tools as plain JSON and writes one outer entry with its arguments and the size of its text', function () {
    CatalogCalls::serve();

    $response = CatalogCalls::call('search_tools', ['query' => 'echo', 'limit' => 5]);
    $text = $response->json('result.content.0.text');
    $entries = AuditLog::entries();

    expect($response->headers->get('Content-Type'))->toBe('application/json')
        ->and($entries)->toHaveCount(1)
        ->and($entries[0]['tool'])->toBe('search_tools')
        ->and($entries[0]['decision'])->toBe('allowed')
        ->and($entries[0]['reason'])->toBeNull()
        ->and($entries[0]['outcome'])->toBe('success')
        ->and($entries[0]['params'])->toBe(['query' => 'echo', 'limit' => 5])
        ->and($entries[0]['details'])->toBe([])
        ->and($entries[0]['principal'])->toBe('tests')
        ->and($entries[0]['output_bytes'])->toBe(is_string($text) ? strlen($text) : -1)
        ->and($entries[0]['duration_ms'])->toBeInt();
});

it('CL5 audits a search_tools that laravel/mcp refuses as an error', function () {
    CatalogCalls::serve();

    $result = CatalogCalls::call('search_tools', ['limit' => 500])->json('result');
    $entries = AuditLog::entries();

    expect(data_get($result, 'isError'))->toBeTrue()
        ->and($entries)->toHaveCount(1)
        ->and($entries[0]['tool'])->toBe('search_tools')
        ->and($entries[0]['outcome'])->toBe('error')
        ->and($entries[0]['params'])->toBe(['limit' => 500]);
});

it('CL6 treats search_tools and execute_tools as unknown names with the catalog off', function (string $name) {
    CatalogCalls::serve(['runtime-mcp.catalog.enabled' => false]);

    currentTest()->mcpPost('tools/call', ['name' => $name, 'arguments' => (object) []])
        ->assertStatus(400)
        ->assertJsonPath('error.message', "Tool [{$name}] not found.");

    expect(CatalogCalls::audit())->toBe([[$name, 'denied', 'unknown_tool', null]]);
})->with(['search_tools', 'execute_tools']);

it('CL7 still answers search_tools, and reports why, when the outer entry cannot be written', function () {
    CatalogCalls::serve(['logging.channels.runtime-mcp' => [
        'driver' => 'daily',
        'path' => __FILE__.'/no-such-dir/audit.log', // the parent is a file: no directory can be made
        'formatter' => Monolog\Formatter\JsonFormatter::class,
    ]]);
    Exceptions::fake();

    $batch = CatalogCalls::batch(CatalogCalls::messages(CatalogCalls::call('search_tools', ['query' => 'echo'])));

    expect($batch['ok'])->toBeTrue()
        ->and(array_column(is_array($batch['tools']) ? $batch['tools'] : [], 'name'))->toBe(['echo', 'second_echo']);

    Exceptions::assertReported(AuditUnavailable::class);
});
