<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Security\Auth\ApiKey;
use SinaSaeedi\RuntimeMcp\Security\Auth\ApiKeyRing;
use SinaSaeedi\RuntimeMcp\Security\Auth\KeyIssuer;

/*
 * Spec 5.3, 7.2 (01-27): the key, its pair and the new RUNTIME_MCP_API_KEYS value. Helpers start with "keyIssuer".
 * Fixed keys for the current value, so a failure message is the same on every run. They are not secrets.
 */

function keyIssuerPair(string $name, string $label): string
{
    return $name.':'.ApiKey::hash(ApiKey::PREFIX.str_pad($label, 43, 'x'));
}

function keyIssuerFor(mixed $current): KeyIssuer
{
    config(['runtime-mcp.auth.api_keys' => $current]);

    return app(KeyIssuer::class);
}

it('KI1 makes a key in the spec 5.3 format and its pair', function () {
    $issued = keyIssuerFor(null)->issue('sina');

    expect($issued->name)->toBe('sina')
        ->and($issued->key)->toMatch('/^rmcp_[A-Za-z0-9_-]{43}$/D')
        ->and(strlen($issued->key))->toBe(ApiKey::LENGTH)
        ->and($issued->pair)->toBe('sina:'.hash('sha256', $issued->key))
        ->and($issued->envValue)->toBe($issued->pair)
        ->and($issued->warnings)->toBe([]);
});

it('KI2 makes a new key on every call', function () {
    $issuer = keyIssuerFor(null);

    expect($issuer->issue('sina')->key)->not->toBe($issuer->issue('sina')->key);
});

it('KI3 appends the pair to the current value, and the server then knows the key by that name', function () {
    $issued = keyIssuerFor(keyIssuerPair('ops', 'a'))->issue('sina');

    expect($issued->envValue)->toBe(keyIssuerPair('ops', 'a').','.$issued->pair)
        ->and($issued->warnings)->toBe([])
        ->and((new ApiKeyRing($issued->envValue))->match($issued->key))->toBe('sina');
});

it('KI4 writes the current pairs again with lower-case hashes, no spaces and no empty segments', function () {
    $current = ' ops:'.strtoupper(substr(keyIssuerPair('ops', 'a'), 4)).' , ,'.keyIssuerPair('ci', 'b').',';
    $issued = keyIssuerFor($current)->issue('sina');

    expect($issued->envValue)->toBe(keyIssuerPair('ops', 'a').','.keyIssuerPair('ci', 'b').','.$issued->pair);
});

it('KI5 never echoes a malformed value: the new pair alone, and a warning with the positions only', function (mixed $current, string $entries) {
    $issued = keyIssuerFor($current)->issue('sina');

    expect($issued->envValue)->toBe($issued->pair)
        ->and($issued->warnings)->toBe(["The current RUNTIME_MCP_API_KEYS value is malformed ({$entries}). Fix it, then append this pair."]);
})->with([
    'one bad entry' => [keyIssuerPair('ops', 'a').',rmcp_PastedKeyPastedKeyPastedKeyPastedKey12345', 'entry 2'],
    'two bad entries' => ['ops:abc,'.keyIssuerPair('ci', 'b').',sina,'.keyIssuerPair('qa', 'c').',x:y', 'entries 1, 3, 5'],
    'a key-shaped name' => ['rmcp_x:'.str_repeat('a', 64), 'entry 1'],
    'not a string' => [['ops:abc'], 'entry 1'],
]);

it('KI6 warns when the name is already there: a second key for rotation', function () {
    $issued = keyIssuerFor(keyIssuerPair('sina', 'a').','.keyIssuerPair('ops', 'b'))->issue('sina');

    expect($issued->envValue)->toBe(keyIssuerPair('sina', 'a').','.keyIssuerPair('ops', 'b').','.$issued->pair)
        ->and($issued->warnings)->toBe(['A key named "sina" already exists. This adds a second key for rotation; remove the old pair once the new key works.']);
});

it('KI7 compares names exactly: Sina is another principal than sina', function () {
    expect(keyIssuerFor(keyIssuerPair('Sina', 'a'))->issue('sina')->warnings)->toBe([]);
});

it('KI8 refuses a name outside the name rule', function (string $name) {
    expect(fn () => keyIssuerFor(null)->issue($name))->toThrow(InvalidArgumentException::class, KeyIssuer::NAME_RULE);
})->with(['a space' => ['sina saeedi'], 'empty' => [''], '65 characters' => [str_repeat('a', 65)], 'a colon' => ['a:b'], 'a comma' => ['a,b'], 'a newline' => ["sina\n"]]);

it('KI9 refuses a name that starts with the key prefix, in any case', function (string $name) {
    expect(fn () => keyIssuerFor(null)->issue($name))->toThrow(InvalidArgumentException::class, KeyIssuer::PREFIX_RULE);
})->with(['rmcp_bot', 'RMCP_bot', 'Rmcp_x']);

it('KI10 accepts every character of the name rule, up to 64', function (string $name) {
    expect(keyIssuerFor(null)->issue($name)->name)->toBe($name);
})->with(['ci.bot-1_X', str_repeat('a', 64), 'rmcp']);
