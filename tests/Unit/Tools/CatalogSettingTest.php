<?php

declare(strict_types=1);

use Laravel\Mcp\Server\Tools\ToolSearch;
use SinaSaeedi\RuntimeMcp\Tools\CatalogSetting;

/*
 * 01-40 Part A: the one reader of catalog.enabled and of laravel/mcp's mcp.tool_search.* values. The two numbers must be
 * the ones ToolSearch::__construct() computes, so the test reads ToolSearch's own (protected) properties.
 */

/** @return array{int, int} ToolSearch's max output bytes and max tool calls for the config of this test */
function catalogToolSearchLimits(): array
{
    $search = new ToolSearch([]);
    $bytes = (new ReflectionProperty(ToolSearch::class, 'maxOutputBytes'))->getValue($search);
    $calls = (new ReflectionProperty(ToolSearch::class, 'maxToolCalls'))->getValue($search);

    return [is_int($bytes) ? $bytes : -1, is_int($calls) ? $calls : -1];
}

it('CS1 is on only for the bool true', function (mixed $value, bool $enabled, bool $valid) {
    currentTest()->bootWith(['runtime-mcp.catalog.enabled' => $value]);
    $catalog = app(CatalogSetting::class);

    expect($catalog->enabled())->toBe($enabled)
        ->and($catalog->valueIsValid())->toBe($valid);
})->with([
    'true' => [true, true, true],
    'false' => [false, false, true],
    'null' => [null, false, true],
    'the string true' => ['true', false, false],
    'the string 1' => ['1', false, false],
    'one' => [1, false, false],
    'a list' => [[true], false, false],
]);

it('CS2 reads mcp.tool_search.* exactly as ToolSearch does', function (mixed $value) {
    currentTest()->bootWith(['mcp.tool_search.max_output_bytes' => $value, 'mcp.tool_search.max_tool_calls' => $value]);
    $catalog = app(CatalogSetting::class);

    expect([$catalog->toolSearchMaxOutputBytes(), $catalog->toolSearchMaxToolCalls()])->toBe(catalogToolSearchLimits());
})->with([
    'an int' => [70_000],
    'a small int' => [3],
    'zero' => [0],
    'negative' => [-5],
    'a digit string' => ['70000'],
    'digits then a word' => ['12abc'],
    'a word' => ['abc'],
    'a float' => [1.9],
    'a large float' => [70_000.9],
    'true' => [true],
    'false' => [false],
    'an empty list' => [[]],
    'a list' => [[7]],
    'null' => [null],
]);

it('CS3 uses ToolSearch\'s code defaults when the keys are missing', function () {
    currentTest()->bootWith(['mcp.tool_search' => []]);
    $catalog = app(CatalogSetting::class);

    expect([$catalog->toolSearchMaxOutputBytes(), $catalog->toolSearchMaxToolCalls()])->toBe(catalogToolSearchLimits())
        ->and($catalog->toolSearchMaxOutputBytes())->toBe(CatalogSetting::DEFAULT_MAX_OUTPUT_BYTES)
        ->and($catalog->toolSearchMaxToolCalls())->toBe(CatalogSetting::DEFAULT_MAX_TOOL_CALLS);
});
