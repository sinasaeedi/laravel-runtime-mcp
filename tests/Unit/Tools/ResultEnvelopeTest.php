<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Tools\ResultEnvelope;
use SinaSaeedi\RuntimeMcp\Tools\ToolResult;

it('has exactly the five keys of spec 4.4, in order', function () {
    $envelope = ResultEnvelope::fromResult(ToolResult::partial(['rows' => [[1]]], 'Cut.', 4), 'staging')->toArray();

    expect(array_keys($envelope))->toBe(['environment', 'status', 'note', 'truncated', 'data'])
        ->and($envelope)->toBe([
            'environment' => 'staging',
            'status' => 'partial',
            'note' => 'Cut.',
            'truncated' => 4,
            'data' => ['rows' => [[1]]],
        ]);
});

it('gives an unavailable result null data and its note', function () {
    expect(ResultEnvelope::fromResult(ToolResult::unavailable('No file.'), 'production')->toArray())->toBe([
        'environment' => 'production',
        'status' => 'unavailable',
        'note' => 'No file.',
        'truncated' => false,
        'data' => null,
    ]);
});

it('encodes without escaped slashes or unicode, and replaces invalid UTF-8', function () {
    $json = ResultEnvelope::fromResult(ToolResult::ok(['url' => 'https://a.test/x', 'name' => 'Zoë', 'line' => "caf\xE9"]), 'testing')->toJson();

    expect($json)->toBe('{"environment":"testing","status":"ok","note":null,"truncated":false,"data":{"url":"https://a.test/x","name":"Zoë","line":"caf'."\u{FFFD}".'"}}');
});

it('throws when the data cannot be JSON', function () {
    $thrown = null;

    try {
        ResultEnvelope::fromResult(ToolResult::ok(['ratio' => INF]), 'testing')->toJson();
    } catch (JsonException $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(JsonException::class);
});
