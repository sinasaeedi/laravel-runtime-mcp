<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Tools\Status;
use SinaSaeedi\RuntimeMcp\Tools\ToolResult;

/* 01-17 Part C: the list key the output cap trims by, and capped(), the one way to make a cut result. */

it('TL1 marks the list and keeps everything else', function () {
    $result = ToolResult::partial(['rows' => [[1], [2]]], 'More rows exist.', 3, 2)->withListKey('rows');

    expect($result->listKey)->toBe('rows')
        ->and($result->status)->toBe(Status::Partial)
        ->and($result->data)->toBe(['rows' => [[1], [2]]])
        ->and($result->note)->toBe('More rows exist.')
        ->and($result->truncated)->toBe(3)
        ->and($result->rowCount)->toBe(2)
        ->and(ToolResult::ok(['rows' => []])->listKey)->toBeNull();
});

it('TL2 keeps the list key through withContent(), as RunTool and RedactStage call it', function () {
    $result = ToolResult::ok(['data' => ['entries' => [['a']]]])->withListKey('data.entries')->withContent(['data' => ['entries' => [['b']]]], null);

    expect($result->listKey)->toBe('data.entries')
        ->and(ToolResult::ok([[1], [2]])->withListKey('*')->listKey)->toBe('*');
});

it('TL3 throws for a key that does not lead to a list', function (Closure $build, string $message) {
    expect($build)->toThrow(LogicException::class, $message);
})->with([
    'a missing key' => [fn () => ToolResult::ok(['rows' => []])->withListKey('entries'), 'The list key [entries] does not lead to a list in the data.'],
    'a map' => [fn () => ToolResult::ok(['rows' => ['a' => 1]])->withListKey('rows'), 'The list key [rows] does not lead to a list in the data.'],
    'a scalar' => [fn () => ToolResult::ok(['rows' => 3])->withListKey('rows'), 'The list key [rows] does not lead to a list in the data.'],
    'star on a map' => [fn () => ToolResult::ok(['rows' => []])->withListKey('*'), 'The list key [*] does not lead to a list in the data.'],
    'unavailable' => [fn () => ToolResult::unavailable('No file.')->withListKey('*'), 'The list key [*] does not lead to a list in the data.'],
    'an empty key' => [fn () => ToolResult::ok(['rows' => []])->withListKey(''), "[] is not a list key: use '*' or a dot path of names."],
    'a double dot' => [fn () => ToolResult::ok(['rows' => []])->withListKey('a..b'), "[a..b] is not a list key: use '*' or a dot path of names."],
    // Arr::get() finds the literal key, but the cap writes the trimmed list with Arr::set() as nested keys.
    'a literal dotted key' => [fn () => ToolResult::ok(['a.b' => [[1]]])->withListKey('a.b'), 'The list key [a.b] is one key with a dot in the data; a list key is a dot path of nested keys.'],
]);

it('TL4 makes a cut result partial, keeps an unavailable one unavailable, and drops the list key', function () {
    $cut = ToolResult::ok(['rows' => [[1]]], 1)->withListKey('rows')->capped(['rows' => []], 'Cut.', 1, 0);
    $empty = ToolResult::empty('Nothing.', ['columns' => ['a']])->capped(['columns' => []], 'Cut.', 5, 0);
    $unavailable = ToolResult::unavailable('No file.')->capped(null, 'No f…[cut 4 bytes]', false, null);

    expect($cut->status)->toBe(Status::Partial)
        ->and($cut->truncated)->toBe(1)
        ->and($cut->rowCount)->toBe(0)
        ->and($cut->listKey)->toBeNull()
        ->and($empty->status)->toBe(Status::Partial)
        ->and($unavailable->status)->toBe(Status::Unavailable)
        ->and($unavailable->note)->toBe('No f…[cut 4 bytes]')
        ->and(fn () => ToolResult::unavailable('No file.')->capped(null, 'x', 3, null))->toThrow(LogicException::class);
});
