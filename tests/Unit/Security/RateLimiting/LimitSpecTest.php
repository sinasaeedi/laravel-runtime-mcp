<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Security\RateLimiting\LimitSpec;

/* Spec 4.5, 5.8, 01-17 Part A: one limit, written "attempts,minutes". */

it('LS1 parses attempts and minutes, and gives the decay in seconds', function (string $value, int $attempts, int $minutes) {
    $spec = LimitSpec::parse($value);

    expect($spec?->maxAttempts)->toBe($attempts)
        ->and($spec?->decayMinutes)->toBe($minutes)
        ->and($spec?->decaySeconds())->toBe($minutes * 60);
})->with([
    'the principal default' => ['120,1', 120, 1],
    'the db_query default' => ['60,1', 60, 1],
    'one call' => ['1,1', 1, 1],
    'five minutes' => ['30,5', 30, 5],
    'the largest' => ['999999999,999999', 999_999_999, 999_999],
]);

it('LS2 refuses anything else, so the caller uses the default', function (mixed $value) {
    expect(LimitSpec::parse($value))->toBeNull();
})->with([
    'a word' => ['abc'],
    'zero attempts' => ['0,1'],
    'zero minutes' => ['120,0'],
    'an int' => [120],
    'null' => [null],
    'a space' => ['120, 1'],
    'a leading space' => [' 120,1'],
    'three parts' => ['120,1,1'],
    'negative' => ['-1,1'],
    'a fraction' => ['1.5,1'],
    'too many attempt digits' => ['1234567890,1'],
    'too many minute digits' => ['1,1234567'],
    'a trailing newline' => ["120,1\n"],
    'a leading zero' => ['0120,1'],
    'an array' => [['120,1']],
]);

it('LS3 describes itself for the notes and doctor', function (string $value, string $text) {
    expect(LimitSpec::parse($value)?->describe())->toBe($text);
})->with([
    ['120,1', '120 calls per minute'],
    ['1,1', '1 call per minute'],
    ['60,5', '60 calls per 5 minutes'],
]);
