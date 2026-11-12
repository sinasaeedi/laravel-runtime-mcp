<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Plugins\Logs\ErrorFingerprint;

/*
 * 01-26 Part A (spec 6.10): numbers, UUIDs and IDs in a message are replaced, in a fixed order, so one cause is one
 * pattern; the fingerprint is a short hash of the level and the pattern.
 */

/** The message, then its pattern: Part A's table. */
const ERROR_PATTERNS = [
    'a number' => ['Order 11740 failed to sync', 'Order {n} failed to sync'],
    'an 8-digit number is a number, not hex' => ['Order 12345678 failed to sync', 'Order {n} failed to sync'],
    'a 17-digit number is a number, not an ID' => ['Order 12345678901234567 failed', 'Order {n} failed'],
    'a UUID, then a number with a unit' => ['Job 9b2c6f0e-1d2a-4c3b-9f8e-2a1b3c4d5e6f timed out after 60s', 'Job {uuid} timed out after {n}s'],
    'a UUID of digits only' => ['Job 12345678-1234-1234-1234-123456789012 lost', 'Job {uuid} lost'],
    'a hex ID after a colon' => ['Cache key user:7f3a9c21d4 missing', 'Cache key user:{hex} missing'],
    'a hex ID after an underscore' => ['Cache key user_1b9e0a77c2 missing', 'Cache key user_{hex} missing'],
    'a long hex ID is hex, not an ID' => ['Commit a1b2c3d4e5f6a7b8c9d0 not found', 'Commit {hex} not found'],
    'a ULID' => ['Import 01JB4X7ZQ3K9M2N8P5R6S7T0VW failed', 'Import {id} failed'],
    'a provider ID after an underscore' => ['Charge ch_3MmlLrLkdIwHu7ix0snN0B15 failed', 'Charge ch_{id} failed'],
    'an IP address' => ['SQLSTATE[08006] connection to server at 10.0.3.7 failed', 'SQLSTATE[{n}] connection to server at {n}.{n} failed'],
    'a SQLSTATE with letters' => ['SQLSTATE[HY000] [2002] Connection refused', 'SQLSTATE[HY{n}] [{n}] Connection refused'],
    'a decimal' => ['Request took 1.5s', 'Request took {n}s'],
    'hex letters with no digit stay' => ['Hash deadbeef seen', 'Hash deadbeef seen'],
    'a long word with no digit stays' => ['ServiceUnavailableHttpException thrown', 'ServiceUnavailableHttpException thrown'],
    'a redaction marker stays' => ['Upload failed: [redacted:aws_access_key] denied', 'Upload failed: [redacted:aws_access_key] denied'],
    'a short word with digits' => ['utf8mb4 collation mismatch', 'utf{n}mb{n} collation mismatch'],
    'whitespace collapses and trims' => ["  Two\t spaces \n here  ", 'Two spaces here'],
];

it('EF1 gives the pattern of each row of the table', function (string $case) {
    [$message, $pattern] = ERROR_PATTERNS[$case];

    expect(ErrorFingerprint::pattern($message))->toBe($pattern);
})->with(array_keys(ERROR_PATTERNS));

/** Two messages that differ only in a number or an ID: one cause. */
const ERROR_SAME_CAUSE = [
    'numbers of 7 and 8 digits' => ['Order 1234567 failed', 'Order 12345678 failed'],
    'two hex IDs after an underscore' => ['Cache key user_7f3a9c21d4 missing', 'Cache key user_1b9e0a77c2 missing'],
    'two ULIDs, upper and lower case' => ['Import 01JB4X7ZQ3K9M2N8P5R6S7T0VW failed', 'Import 01jb4x8ab4k9m2n8p5r6s7t0vw failed'],
    'two provider IDs' => ['Charge ch_3MmlLrLkdIwHu7ix0snN0B15 failed', 'Charge ch_9ZkeLrLkdIwHu7ix0snN0Q77 failed'],
    'two UUIDs' => ['Job 9b2c6f0e-1d2a-4c3b-9f8e-2a1b3c4d5e6f lost', 'Job 0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0 lost'],
    'a whole and a decimal number' => ['Request took 2s', 'Request took 1.5s'],
    'two IP addresses' => ['No route to 10.0.3.7', 'No route to 192.168.100.27'],
];

it('EF2 gives one pattern for messages that differ only in a number or an ID', function (string $case) {
    [$a, $b] = ERROR_SAME_CAUSE[$case];

    expect(ErrorFingerprint::pattern($a))->toBe(ErrorFingerprint::pattern($b))
        ->and(ErrorFingerprint::of('error', ErrorFingerprint::pattern($a)))->toBe(ErrorFingerprint::of('error', ErrorFingerprint::pattern($b)));
})->with(array_keys(ERROR_SAME_CAUSE));

/** Two messages with another cause: two patterns. */
const ERROR_OTHER_CAUSE = [
    'another word' => ['Payment declined', 'Refund declined'],
    'a word less' => ['Facade root not set', 'Facade root set'],
    'hex letters with no digit' => ['Hash deadbeef seen', 'Hash deadbeee seen'],
    'a short mixed word' => ['Driver utf8mb4 missing', 'Driver utf8mb3x missing'],
];

it('EF3 gives two patterns for messages with another cause', function (string $case) {
    [$a, $b] = ERROR_OTHER_CAUSE[$case];

    expect(ErrorFingerprint::pattern($a))->not->toBe(ErrorFingerprint::pattern($b));
})->with(array_keys(ERROR_OTHER_CAUSE));

it('EF4 hashes the level and the pattern into 12 hex characters', function () {
    $pattern = ErrorFingerprint::pattern('Order 11740 failed to sync');

    expect(ErrorFingerprint::of('error', $pattern))->toBe(substr(sha1('error|Order {n} failed to sync'), 0, 12))
        ->and(ErrorFingerprint::of('error', $pattern))->toMatch('/^[0-9a-f]{12}$/D')
        ->and(ErrorFingerprint::of('critical', $pattern))->not->toBe(ErrorFingerprint::of('error', $pattern));
});

it('EF5 cuts the pattern to 500 characters, on a character, and keeps invalid UTF-8 apart and valid', function () {
    $long = ErrorFingerprint::pattern(str_repeat('é', 600));
    $bad = ErrorFingerprint::pattern("Upload of \xC3\x28 failed");

    expect(mb_strlen($long))->toBe(ErrorFingerprint::MAX_PATTERN)
        ->and(mb_check_encoding($long, 'UTF-8'))->toBeTrue()
        ->and(ErrorFingerprint::pattern(str_repeat('a', 499).' b c'))->toBe(str_repeat('a', 499).' ')
        ->and(mb_check_encoding($bad, 'UTF-8'))->toBeTrue()
        ->and($bad)->toStartWith('Upload of ')->toEndWith(' failed')
        ->and($bad)->not->toBe(ErrorFingerprint::pattern("Download of \xC3\x28 failed"));
});
