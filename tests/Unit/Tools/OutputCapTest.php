<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Tools\OutputCap;
use SinaSaeedi\RuntimeMcp\Tools\ResultEnvelope;
use SinaSaeedi\RuntimeMcp\Tools\Status;
use SinaSaeedi\RuntimeMcp\Tools\ToolResult;

/*
 * Spec 4.4 step 8, 5.8, 01-17 Part C: the cut itself. Every capped result is at most max_bytes of envelope
 * JSON, encoded exactly as AuditStage encodes it, and says what was cut.
 */

function outputCapWith(int $max = 1000): OutputCap
{
    currentTest()->bootWith(['runtime-mcp.output.max_bytes' => $max]);

    return app(OutputCap::class);
}

function outputCapSize(ToolResult $result): int
{
    return strlen(ResultEnvelope::fromResult($result, 'testing')->toJson());
}

/**
 * The kept text and the byte count of a "…[cut N bytes]" marker at its end.
 *
 * @return array{string, int}
 */
function outputCapMarker(mixed $text, string $pattern = '/^(.*)…\[cut (\d+) bytes\]$/su'): array
{
    if (! is_string($text) || preg_match($pattern, $text, $match) !== 1) {
        throw new UnexpectedValueException('No cut marker at the end of the text.');
    }

    return [$match[1], (int) $match[2]];
}

/** @return list<array{int, string}> rows of about 60 bytes of JSON each */
function outputCapRows(int $count): array
{
    return array_map(fn (int $i): array => [$i, 'customer number '.str_pad((string) $i, 30, '0', STR_PAD_LEFT)], range(1, $count));
}

it('OC1 returns a result under the cap unchanged', function () {
    $result = ToolResult::ok(['rows' => outputCapRows(5)], 5)->withListKey('rows');

    expect(outputCapWith()->cap($result, 'testing'))->toBe($result);
});

it('OC2 drops rows from the end: the most that fit, an exact count, a note, and rows kept as the row count', function () {
    $rows = outputCapRows(100);
    $result = ToolResult::ok(['columns' => ['id', 'name'], 'rows' => $rows], 100)->withListKey('rows');

    $cut = outputCapWith()->cap($result, 'testing');
    $kept = $cut->rowCount ?? -1;
    $oneMore = $result->capped(['columns' => ['id', 'name'], 'rows' => array_slice($rows, 0, $kept + 1)], sprintf(OutputCap::TRIMMED, 1000, 99 - $kept, 100, 'rows'), 99 - $kept, $kept + 1);

    expect($cut->status)->toBe(Status::Partial)
        ->and($kept)->toBeGreaterThan(5)
        ->and($cut->data)->toBe(['columns' => ['id', 'name'], 'rows' => array_slice($rows, 0, $kept)])
        ->and($cut->truncated)->toBe(100 - $kept)
        ->and($cut->note)->toBe(sprintf(OutputCap::TRIMMED, 1000, 100 - $kept, 100, 'rows'))
        ->and($cut->note)->toBe("The result was larger than 1000 bytes; the last {$cut->truncated} of 100 rows were omitted. Narrow the request.")
        ->and(outputCapSize($cut))->toBeLessThanOrEqual(1000)
        ->and(outputCapSize($oneMore))->toBeGreaterThan(1000);
});

it('OC3 adds the dropped rows to the tool own count', function () {
    $result = ToolResult::partial(['rows' => outputCapRows(100)], 'The last 7 rows were omitted.', 7, 100)->withListKey('rows');

    $cut = outputCapWith()->cap($result, 'testing');
    $dropped = 100 - count(is_array($cut->data) && is_array($cut->data['rows']) ? $cut->data['rows'] : []);

    expect($cut->truncated)->toBe(7 + $dropped)
        ->and($cut->note)->toBe('The last 7 rows were omitted. '.sprintf(OutputCap::TRIMMED, 1000, $dropped, 100, 'rows'));
});

it('OC4 keeps truncated false when the tool could not count, and gives the number in the note only', function () {
    $result = ToolResult::partial(['rows' => outputCapRows(100)], 'More than 100 rows match.', false, 100)->withListKey('rows');

    $cut = outputCapWith()->cap($result, 'testing');

    expect($cut->truncated)->toBeFalse()
        ->and($cut->status)->toBe(Status::Partial)
        ->and($cut->note)->toStartWith('More than 100 rows match. The result was larger than 1000 bytes; the last ')
        ->and(outputCapSize($cut))->toBeLessThanOrEqual(1000);
});

it('OC5 trims a list that is the data itself, or one at a dot path, and keeps a null row count', function () {
    $star = outputCapWith()->cap(ToolResult::ok(outputCapRows(100))->withListKey('*'), 'testing');
    $nested = app(OutputCap::class)->cap(ToolResult::ok(['result' => ['rows' => outputCapRows(100)], 'meta' => 'kept'])->withListKey('result.rows'), 'testing');

    expect($star->note)->toContain(' of 100 items were omitted.')
        ->and(is_array($star->data) && array_is_list($star->data))->toBeTrue()
        ->and($star->rowCount)->toBeNull()
        ->and($nested->note)->toContain(' of 100 rows were omitted.')
        ->and(is_array($nested->data) ? $nested->data['meta'] : null)->toBe('kept')
        ->and(outputCapSize($star))->toBeLessThanOrEqual(1000)
        ->and(outputCapSize($nested))->toBeLessThanOrEqual(1000);
});

it('OC6 keeps the first item when not even one fits, and cuts its text', function () {
    $entries = [['message' => str_repeat('m', 5_000)], ...array_fill(0, 9, ['message' => 'small'])];
    $result = ToolResult::ok(['entries' => $entries], 10)->withListKey('entries');

    $cut = outputCapWith()->cap($result, 'testing');
    $kept = is_array($cut->data) && is_array($cut->data['entries']) ? $cut->data['entries'] : [];
    [$text, $bytes] = outputCapMarker(is_array($kept[0] ?? null) ? $kept[0]['message'] : null);

    expect($kept)->toHaveCount(1)
        ->and($text)->toBe(str_repeat('m', strlen($text)))
        ->and($bytes)->toBe(5_000 - strlen($text))
        ->and(strlen($text))->toBeGreaterThanOrEqual(OutputCap::MIN_STRING_BYTES)
        ->and($cut->truncated)->toBe(9)
        ->and($cut->rowCount)->toBe(1)
        ->and($cut->note)->toBe(sprintf(OutputCap::TRIMMED_AND_CUT, 1000, 9, 10, 'entries', $bytes))
        ->and(outputCapSize($cut))->toBeLessThanOrEqual(1000);
});

it('OC7 cuts a long multi-byte string on a character boundary, with the marker and the bytes in truncated', function (string $text) {
    $cut = outputCapWith()->cap(ToolResult::ok(['message' => $text]), 'testing');
    [$kept, $bytes] = outputCapMarker(is_array($cut->data) ? $cut->data['message'] : null);

    expect(mb_check_encoding($kept, 'UTF-8'))->toBeTrue()
        ->and(str_starts_with($text, $kept))->toBeTrue()
        ->and($bytes)->toBe(strlen($text) - strlen($kept))
        ->and($cut->truncated)->toBe($bytes)
        ->and($cut->status)->toBe(Status::Partial)
        ->and($cut->note)->toBe(sprintf(OutputCap::CUT, 1000, $bytes))
        ->and(outputCapSize($cut))->toBeLessThanOrEqual(1000);
})->with([
    // Four byte offsets: whatever length the search finds, most of them would split a 4-byte character.
    'mixed' => [str_repeat('é漢😀', 2_000)],
    'offset 0' => [str_repeat('😀', 4_000)],
    'offset 1' => ['a'.str_repeat('😀', 4_000)],
    'offset 2' => ['ab'.str_repeat('😀', 4_000)],
    'offset 3' => ['abc'.str_repeat('😀', 4_000)],
]);

it('OC8 cuts the longest strings first: a shorter one stays whole', function () {
    $cut = outputCapWith()->cap(ToolResult::ok(['a' => str_repeat('a', 3_000), 'b' => str_repeat('b', 300)]), 'testing');
    $data = is_array($cut->data) ? $cut->data : [];

    expect($data['b'])->toBe(str_repeat('b', 300))
        ->and($data['a'])->toContain('…[cut ')
        ->and(outputCapSize($cut))->toBeLessThanOrEqual(1000);
});

it('OC9 returns a prefix of the JSON as one string when many small values are too large', function () {
    $values = array_fill(0, 2_000, 'abcde');
    $json = (string) json_encode($values, ResultEnvelope::JSON_FLAGS);

    $cut = outputCapWith()->cap(ToolResult::ok($values, 2_000), 'testing');
    $kept = is_string($cut->data) ? $cut->data : '';

    // The rows sent cannot be counted in a JSON prefix: no row count, never the tool's 2,000.
    expect($cut->rowCount)->toBeNull()
        ->and($kept)->not->toBe('')
        ->and(str_starts_with($json, $kept))->toBeTrue()
        ->and(strlen($kept))->toBeLessThanOrEqual(500)
        ->and($cut->truncated)->toBe(strlen($json) - strlen($kept))
        ->and($cut->note)->toBe(sprintf(OutputCap::FLATTENED, strlen($kept), strlen($json)))
        ->and(outputCapSize($cut))->toBeLessThanOrEqual(1000);
});

it('OC10 keeps the last resort under the cap when JSON escaping doubles the prefix', function () {
    $cut = outputCapWith()->cap(ToolResult::ok(array_fill(0, 2_000, '"\\/')), 'testing');

    expect(is_string($cut->data))->toBeTrue()
        ->and(strlen(is_string($cut->data) ? $cut->data : ''))->toBeLessThan(500)
        ->and(outputCapSize($cut))->toBeLessThanOrEqual(1000);
});

it('OC11 cuts the note of an unavailable result and keeps it unavailable', function () {
    $note = str_repeat('😀', 400);
    $cut = outputCapWith()->cap(ToolResult::unavailable($note), 'testing');
    [$kept, $bytes] = outputCapMarker($cut->note);

    expect($cut->status)->toBe(Status::Unavailable)
        ->and($cut->truncated)->toBeFalse()
        ->and($cut->data)->toBeNull()
        ->and($kept)->toBe(str_repeat('😀', intdiv(strlen($kept), 4)))
        ->and($bytes)->toBe(strlen($note) - strlen($kept))
        ->and(outputCapSize($cut))->toBeLessThanOrEqual(1000);
});

it('OC12 returns a result that cannot be encoded unchanged, for AuditStage to answer', function () {
    $result = ToolResult::ok(['text' => str_repeat('a', 5_000), 'ratio' => INF]);

    expect(outputCapWith()->cap($result, 'testing'))->toBe($result);
});

it('OC13 never returns empty for a cut result', function () {
    $result = ToolResult::empty('No rows.', ['columns' => array_map(fn (int $i): string => str_pad((string) $i, 100, 'c'), range(1, 30))]);

    $cut = outputCapWith()->cap($result, 'testing');

    expect($cut->status)->toBe(Status::Partial)
        ->and($cut->note)->toStartWith('No rows. The result was too large to return as structured data;')
        ->and(outputCapSize($cut))->toBeLessThanOrEqual(1000);
});

it('OC14 cuts the tool own note when it alone is too long', function () {
    $cut = outputCapWith()->cap(ToolResult::partial(['text' => 'x'], str_repeat('n', 3_000)), 'testing');

    expect($cut->data)->toBe('')
        ->and($cut->truncated)->toBeFalse()
        ->and($cut->note)->toMatch('/^n+…\[cut \d+ bytes\]$/')
        ->and(outputCapSize($cut))->toBeLessThanOrEqual(1000);
});

it('OC15 reads output.max_bytes: a whole number of at least 1000, else 100000', function (mixed $value, int $bytes, bool $valid) {
    currentTest()->bootWith(['runtime-mcp.output.max_bytes' => $value]);
    $cap = app(OutputCap::class);

    expect($cap->maxBytes())->toBe($bytes)
        ->and($cap->limit())->toBe($bytes)
        ->and($cap->maxBytesIsValid())->toBe($valid);
})->with([
    'the default' => [100_000, 100_000, true],
    'an env() string' => ['50000', 50_000, true],
    'the minimum' => [1_000, 1_000, true],
    'below the minimum' => [999, 100_000, false],
    'ten' => [10, 100_000, false],
    'a word' => ['abc', 100_000, false],
    'null' => [null, 100_000, false],
    'a float' => [150_000.0, 100_000, false],
    'too many digits' => ['99999999999999999999', 100_000, false],
]);
