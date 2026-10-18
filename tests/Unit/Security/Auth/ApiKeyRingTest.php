<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Security\Auth\ApiKey;
use SinaSaeedi\RuntimeMcp\Security\Auth\ApiKeyRing;

/*
 * Pest test-file functions are global, so each helper name starts with "apiKeyRing".
 * Fixed keys, so a failure message is the same on every run. They are not secrets.
 */

function apiKeyRingKey(string $label): string
{
    return ApiKey::PREFIX.str_pad($label, 43, 'x');
}

function apiKeyRingEntry(string $name, string $label): string
{
    return $name.':'.ApiKey::hash(apiKeyRingKey($label));
}

it('parses pairs, spaces, empty segments and upper-case hex', function () {
    $value = ' sina:'.strtoupper(ApiKey::hash(apiKeyRingKey('a'))).' ,  ,'.apiKeyRingEntry('ops', 'b').',';
    $ring = new ApiKeyRing($value);

    expect($ring->isValid())->toBeTrue()
        ->and($ring->isEmpty())->toBeFalse()
        ->and($ring->malformedPositions())->toBe([])
        ->and($ring->match(apiKeyRingKey('a')))->toBe('sina')
        ->and($ring->match(apiKeyRingKey('b')))->toBe('ops');
});

it('reports malformed segments by position and then matches nothing', function (string $value, array $positions) {
    $ring = new ApiKeyRing($value);

    expect($ring->isValid())->toBeFalse()
        ->and($ring->isEmpty())->toBeFalse()
        ->and($ring->malformedPositions())->toBe($positions)
        ->and($ring->match(apiKeyRingKey('a')))->toBeNull();
})->with([
    'short hash' => ['sina:abc', [1]],
    'after an empty segment' => [apiKeyRingEntry('sina', 'a').',,bad', [3]],
    '63 hex digits' => [apiKeyRingEntry('sina', 'a').', ops:'.substr(ApiKey::hash('x'), 1), [2]],
    'space in the name' => ['si na:'.ApiKey::hash(apiKeyRingKey('a')), [1]],
    'third field' => [apiKeyRingEntry('sina', 'a').':extra', [1]],
    'name too long' => [str_repeat('a', 65).':'.ApiKey::hash(apiKeyRingKey('a')), [1]],
    'two bad ones' => ['x,'.apiKeyRingEntry('sina', 'a').',y', [1, 3]],
]);

it('treats a non-string value as one malformed segment', function (mixed $value) {
    $ring = new ApiKeyRing($value);

    expect($ring->isValid())->toBeFalse()
        ->and($ring->isEmpty())->toBeFalse()
        ->and($ring->malformedPositions())->toBe([1]);
})->with([
    'array' => [[apiKeyRingEntry('sina', 'a')]],
    'int' => 5,
    'true' => true,
]);

it('is empty and valid without any segment', function (mixed $value) {
    $ring = new ApiKeyRing($value);

    expect($ring->isEmpty())->toBeTrue()
        ->and($ring->isValid())->toBeTrue()
        ->and($ring->match(apiKeyRingKey('a')))->toBeNull();
})->with([
    'null' => null,
    'empty string' => '',
    'commas and spaces' => ' , ,',
]);

it('matches the right name, and nothing for a wrong or empty key', function () {
    $ring = new ApiKeyRing(apiKeyRingEntry('sina', 'a').','.apiKeyRingEntry('ops', 'b'));

    expect($ring->match(apiKeyRingKey('b')))->toBe('ops')
        ->and($ring->match(apiKeyRingKey('c')))->toBeNull()
        ->and($ring->match(''))->toBeNull()
        ->and($ring->match(ApiKey::hash(apiKeyRingKey('a'))))->toBeNull(); // the hash is not the key
});

it('lets two pairs share a name (key rotation)', function () {
    $ring = new ApiKeyRing(apiKeyRingEntry('sina', 'old').','.apiKeyRingEntry('sina', 'new'));

    expect($ring->match(apiKeyRingKey('old')))->toBe('sina')
        ->and($ring->match(apiKeyRingKey('new')))->toBe('sina');
});

it('returns the first name when two names share a hash', function () {
    $ring = new ApiKeyRing(apiKeyRingEntry('first', 'a').','.apiKeyRingEntry('second', 'a'));

    expect($ring->match(apiKeyRingKey('a')))->toBe('first');
});

// Spec 11.2 "timing-safe compare": every entry is compared, whichever key is presented.
it('compares every entry, stored hash first, for the first, the last and a wrong key', function (string $presented) {
    $labels = ['a', 'b', 'c', 'd', 'e'];
    $value = implode(',', array_map(fn (string $label): string => apiKeyRingEntry('n'.$label, $label), $labels));
    $calls = [];
    $ring = new ApiKeyRing($value, function (string $known, string $user) use (&$calls): bool {
        $calls[] = [$known, $user];

        return hash_equals($known, $user);
    });

    $ring->match(apiKeyRingKey($presented));

    expect($calls)->toBe(array_map(
        fn (string $label): array => [ApiKey::hash(apiKeyRingKey($label)), ApiKey::hash(apiKeyRingKey($presented))],
        $labels,
    ));
})->with([
    'first' => 'a',
    'last' => 'e',
    'wrong' => 'z',
]);

it('uses hash_equals by default', function () {
    $property = new ReflectionProperty(ApiKeyRing::class, 'equals');
    $equals = $property->getValue(new ApiKeyRing(null));
    assert($equals instanceof Closure);

    expect((new ReflectionFunction($equals))->getName())->toBe('hash_equals');
});

it('reads runtime-mcp.auth.api_keys', function () {
    config(['runtime-mcp.auth.api_keys' => apiKeyRingEntry('sina', 'a')]);

    expect(ApiKeyRing::fromConfig(app('config'))->match(apiKeyRingKey('a')))->toBe('sina');
});
