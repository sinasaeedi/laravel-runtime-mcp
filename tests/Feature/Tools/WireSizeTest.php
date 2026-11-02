<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Testing\TestResponse;
use SinaSaeedi\RuntimeMcp\Plugins\PluginRegistry;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Plugins\BigResultPlugin;
use SinaSaeedi\RuntimeMcp\Tests\Support\FixtureTools;
use SinaSaeedi\RuntimeMcp\Tests\Support\Gates;
use SinaSaeedi\RuntimeMcp\Tests\Support\ProtocolEra;
use SinaSaeedi\RuntimeMcp\Tools\ResultEnvelope;
use Symfony\Component\HttpFoundation\Response;

/*
 * Spec 4.4, 11.2 "Wire size", 01-17 audit Q1: the envelope travels two times. The decoded result (the
 * label, the text copy and structuredContent) is at most 2 x max_bytes + 1,024 bytes. The raw body is
 * larger: JSON escapes the text copy again, and laravel/mcp escapes "/" (JsonRpcResponse::toJson()).
 */

/** @return TestResponse<Response> */
function wireSizeCall(ProtocolEra $era): TestResponse
{
    currentTest()->bootWith(['runtime-mcp.enabled' => true]);
    app()->scoped(PluginRegistry::class, fn (Application $app): PluginRegistry => new PluginRegistry(
        $app, $app->make(Repository::class), [BigResultPlugin::class],
    ));
    Gates::allow('big_result');

    // About 300 KB of rows with quotes, backslashes, slashes and multi-byte text.
    return FixtureTools::call('big_result', ['kind' => 'wire', 'count' => 3_000], $era);
}

it('OW1 keeps the decoded result within 2 x max_bytes + 1,024 bytes', function (ProtocolEra $era) {
    $response = wireSizeCall($era);
    $label = FixtureTools::text($response, 0);
    $text = FixtureTools::text($response, 1);
    $structured = (string) json_encode(FixtureTools::envelope($response), ResultEnvelope::JSON_FLAGS);

    expect($text)->toBe($structured)
        ->and(strlen($text))->toBeLessThanOrEqual(100_000)
        ->and(strlen($label) + strlen($text) + strlen($structured))->toBeLessThanOrEqual(2 * 100_000 + 1_024)
        ->and(FixtureTools::envelope($response)['status'])->toBe('partial');
})->with([ProtocolEra::Legacy, ProtocolEra::Modern]);

it('OW2 sends more raw bytes than the decoded bound: JSON escaping on the wire', function (ProtocolEra $era) {
    $response = wireSizeCall($era);

    expect(strlen((string) $response->getContent()))->toBeGreaterThan(2 * 100_000 + 1_024);
})->with([ProtocolEra::Legacy, ProtocolEra::Modern]);
