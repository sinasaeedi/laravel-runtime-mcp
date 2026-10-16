<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Mcp\ClientInfo;

it('reads the client from the 2026-07-28 _meta', function (mixed $info, ?array $expected) {
    expect(ClientInfo::from(['io.modelcontextprotocol/clientInfo' => $info]))->toBe($expected);
})->with([
    'name and version' => [['name' => 'claude-code', 'version' => '2.1.0'], ['name' => 'claude-code', 'version' => '2.1.0']],
    'name only' => [['name' => 'cursor'], ['name' => 'cursor', 'version' => null]],
    'a null version' => [['name' => 'cursor', 'version' => null], ['name' => 'cursor', 'version' => null]],
    'extra keys are dropped' => [['name' => 'codex', 'version' => '1', 'title' => 'Codex'], ['name' => 'codex', 'version' => '1']],
]);

it('returns null when the client is missing or malformed', function (?array $meta) {
    expect(ClientInfo::from($meta))->toBeNull();
})->with([
    'no _meta (a legacy request)' => [null],
    'empty _meta' => [[]],
    'only the protocol keys' => [['io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientCapabilities' => []]],
    'clientInfo is a string' => [['io.modelcontextprotocol/clientInfo' => 'claude-code']],
    'no name' => [['io.modelcontextprotocol/clientInfo' => ['version' => '1']]],
    'an empty name' => [['io.modelcontextprotocol/clientInfo' => ['name' => '']]],
    'a name that is not a string' => [['io.modelcontextprotocol/clientInfo' => ['name' => 42]]],
    'a version that is not a string' => [['io.modelcontextprotocol/clientInfo' => ['name' => 'x', 'version' => 2]]],
    'the key without the prefix' => [['clientInfo' => ['name' => 'x']]],
]);

it('cuts the name to 100 and the version to 50 characters, by character', function () {
    $info = ClientInfo::from(['io.modelcontextprotocol/clientInfo' => [
        'name' => str_repeat('é', 150),
        'version' => str_repeat('ü', 80),
    ]]);

    expect($info)->toBe(['name' => str_repeat('é', 100), 'version' => str_repeat('ü', 50)]);
});
