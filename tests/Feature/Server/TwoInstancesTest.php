<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Tests\Support\ProtocolEra;

it('serves requests that alternate between two app instances', function (ProtocolEra $era) {
    currentTest()->bootWith(['runtime-mcp.enabled' => true]);
    $first = currentTest()->mcpPost($era === ProtocolEra::Legacy ? 'initialize' : 'server/discover', era: $era)->assertOk();

    currentTest()->rebootApplication(); // a second server behind the load balancer

    $second = currentTest()->mcpPost('tools/list', era: $era)->assertOk();

    expect($first->headers->has('MCP-Session-Id'))->toBeFalse()
        ->and($second->json('result.tools'))->toBe([]);
})->with([ProtocolEra::Legacy, ProtocolEra::Modern]);
