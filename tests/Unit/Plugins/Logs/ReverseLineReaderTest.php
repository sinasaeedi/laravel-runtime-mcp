<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Plugins\Logs\Budget;
use SinaSaeedi\RuntimeMcp\Plugins\Logs\ReadEnd;
use SinaSaeedi\RuntimeMcp\Plugins\Logs\ReverseLineReader;
use SinaSaeedi\RuntimeMcp\Tests\Support\LogFiles;

/*
 * 01-25 Part B (spec 5.8): lines newest first, from the end, in 64 KB chunks, within one budget. Files are written as
 * raw bytes here; the formats are LogEntryParserTest's.
 */

beforeEach(fn () => LogFiles::clear());
afterEach(fn () => LogFiles::clear());

/**
 * Every line and the end reason.
 *
 * @return array{list<string>, ReadEnd}
 */
function reverseRead(string $path, int $budget = 10_000_000, ?Budget $shared = null): array
{
    $lines = (new ReverseLineReader)->lines($path, $shared ?? new Budget($budget));

    return [iterator_to_array($lines, false), $lines->getReturn()];
}

it('BG1 grants bytes until the budget is spent, and refuses a budget below 1', function () {
    $budget = new Budget(100);

    expect($budget->take(60))->toBe(60)
        ->and($budget->spent())->toBeFalse()
        ->and($budget->take(60))->toBe(40)
        ->and($budget->take(1))->toBe(0)
        ->and($budget->spent())->toBeTrue()
        ->and($budget->used())->toBe(100)
        ->and(fn () => new Budget(0))->toThrow(LogicException::class, 'A read budget is 1 byte or more.');
});

it('RL1 yields lines newest first, with or without a last newline, and drops a trailing \r', function (string $content, array $lines) {
    $path = LogFiles::write('a.log', [$content]);

    expect(reverseRead($path))->toBe([$lines, ReadEnd::Start]);
})->with([
    'a last newline' => ["one\ntwo\nthree\n", ['three', 'two', 'one']],
    'no last newline' => ["one\ntwo\nthree", ['three', 'two', 'one']],
    'CRLF' => ["one\r\ntwo\r\n", ['two', 'one']],
    'an empty line inside' => ["one\n\nthree\n", ['three', '', 'one']],
    'an empty file' => ['', []],
    'one newline' => ["\n", ['']],
]);

it('RL2 splits no line and no UTF-8 character at a 64 KB chunk edge', function () {
    $lines = [];

    // Lines of 1 to 700 bytes with two-, three- and four-byte characters, about 216 KB, so lines cross three edges.
    for ($i = 0; $i < 1_000; $i++) {
        $lines[] = "{$i} ".str_repeat(['a', 'é', '€', '😀'][$i % 4], ($i * 37) % 170);
    }

    $path = LogFiles::write('utf8.log', [implode("\n", $lines)."\n"]);
    [$read, $end] = reverseRead($path);

    expect(filesize($path))->toBeGreaterThan(3 * ReverseLineReader::CHUNK_BYTES)
        ->and($end)->toBe(ReadEnd::Start)
        ->and($read)->toBe(array_reverse($lines));
});

it('RL3 stops at the budget, reads exactly that many bytes, and yields only whole lines from the end', function () {
    $lines = array_map(fn (int $i): string => "line {$i} ".str_repeat('x', $i % 50), range(1, 3_000));
    $path = LogFiles::write('big.log', [implode("\n", $lines)."\n"]);
    $budget = new Budget(70_000);
    [$read, $end] = reverseRead($path, shared: $budget);

    expect($end)->toBe(ReadEnd::Budget)
        ->and($budget->used())->toBe(70_000)
        ->and($read)->toBe(array_slice(array_reverse($lines), 0, count($read)))
        // The bytes of the yielded lines and their newlines fit in the budget; the next older line did not.
        ->and(strlen(implode("\n", $read)) + 1)->toBeLessThanOrEqual(70_000)
        ->and(strlen(implode("\n", array_slice(array_reverse($lines), 0, count($read) + 1))) + 1)->toBeGreaterThan(70_000);
});

it('RL4 shares one budget across files', function () {
    $first = LogFiles::write('one.log', [str_repeat("0123456789\n", 1_000)]);
    $second = LogFiles::write('two.log', [str_repeat("abcdefghij\n", 1_000)]);
    $budget = new Budget(15_000);

    [$a, $endA] = reverseRead($first, shared: $budget);
    [$b, $endB] = reverseRead($second, shared: $budget);

    expect($endA)->toBe(ReadEnd::Start)
        ->and(count($a))->toBe(1_000)
        ->and($endB)->toBe(ReadEnd::Budget)
        ->and(count($b))->toBe(intdiv(15_000 - 11_000, 11))
        ->and($budget->used())->toBe(15_000);
});

it('RL5 names a missing and an unreadable file before it opens them', function () {
    $unreadable = LogFiles::write('secret.log', ["x\n"]);
    chmod($unreadable, 0000);

    expect(ReverseLineReader::problem(LogFiles::path('none.log')))->toBe('File none.log does not exist.')
        ->and(ReverseLineReader::problem(LogFiles::dir()))->toBe('File '.basename(LogFiles::dir()).' does not exist.')
        ->and(ReverseLineReader::problem(LogFiles::write('ok.log', ["x\n"])))->toBeNull();

    // root reads a file with mode 0000, so the last case would prove nothing: a skip shows it (GitHub's runner is not root).
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        currentTest()->markTestSkipped('root can read every file');
    }

    expect(ReverseLineReader::problem($unreadable))->toBe('File secret.log is not readable by the PHP process.');
});

it('RL6 returns Unreadable when fopen() fails, and Changed when the file shrinks during the read', function () {
    [$none, $end] = reverseRead(LogFiles::path('gone.log'));
    $path = LogFiles::write('rotating.log', [str_repeat("0123456789\n", 20_000)]);
    $lines = (new ReverseLineReader)->lines($path, new Budget(10_000_000));
    $read = 0;

    foreach ($lines as $line) {
        if (++$read === 1) {
            // copytruncate: the file is emptied while the first chunk is in memory.
            file_put_contents($path, "new\n");
        }
    }

    expect($none)->toBe([])
        ->and($end)->toBe(ReadEnd::Unreadable)
        ->and($lines->getReturn())->toBe(ReadEnd::Changed)
        ->and($read)->toBe(intdiv(ReverseLineReader::CHUNK_BYTES, 11));
});

it('RL7 never reads lines written after it opened the file', function () {
    $path = LogFiles::write('live.log', ["old 1\nold 2\n"]);
    $lines = (new ReverseLineReader)->lines($path, new Budget(10_000));
    $read = [];

    foreach ($lines as $line) {
        $read[] = $line;
        file_put_contents($path, "new\n", FILE_APPEND);
    }

    expect($read)->toBe(['old 2', 'old 1'])
        ->and($lines->getReturn())->toBe(ReadEnd::Start);
});
