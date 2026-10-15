<?php

declare(strict_types=1);

use Laravel\Mcp\Enums\CacheScope;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Cacheable;
use Laravel\Mcp\Server\Transport\FakeTransporter;
use SinaSaeedi\RuntimeMcp\Mcp\McpServer;
use SinaSaeedi\RuntimeMcp\Tests\Support\ProtocolEra;

it('marks every cacheable list as private with a TTL of 0', function (string $method) {
    currentTest()->bootWith(['runtime-mcp.enabled' => true]);

    currentTest()->mcpPost($method, era: ProtocolEra::Modern)->assertOk()
        ->assertJsonPath('result.cacheScope', 'private')
        ->assertJsonPath('result.ttlMs', 0);
})->with(['server/discover', 'tools/list', 'prompts/list']);

it('sets the hints itself and does not rely on the laravel/mcp default', function () {
    $server = app()->make(McpServer::class, ['transport' => new FakeTransporter]);
    $hints = (new ReflectionMethod($server, 'cacheHints'))->invoke($server);

    expect($hints)->toBeArray()->toHaveKeys(Server::CACHEABLE_METHODS);

    assert(is_array($hints));

    foreach ($hints as $hint) {
        assert($hint instanceof Cacheable);
        expect($hint->ttlMs)->toBe(0)
            ->and($hint->scope)->toBe(CacheScope::Private);
    }
});
