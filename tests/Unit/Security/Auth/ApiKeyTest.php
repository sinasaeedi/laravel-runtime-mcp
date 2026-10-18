<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Security\Auth\ApiKey;
use SinaSaeedi\RuntimeMcp\Security\Redaction\Redactor;

it('generates rmcp_ plus 43 base64url characters, a new key each time', function () {
    $keys = [];

    for ($i = 0; $i < 100; $i++) {
        $key = ApiKey::generate();

        expect($key)->toMatch('/^rmcp_[A-Za-z0-9_-]{43}$/D')
            ->and(strlen($key))->toBe(ApiKey::LENGTH);

        $keys[] = $key;
    }

    expect(array_unique($keys))->toHaveCount(100)
        ->and(ApiKey::LENGTH)->toBe(48);
});

it('generates keys that the redaction engine finds (spec 5.6, 01-04)', function () {
    $key = ApiKey::generate();

    expect(app(Redactor::class)->redactString('key='.$key))->not->toContain($key);
});

it('hashes the whole key, prefix included, as lower-case hex SHA-256', function () {
    $key = ApiKey::generate();

    expect(ApiKey::hash($key))->toBe(hash('sha256', $key))
        ->and(ApiKey::hash($key))->toMatch('/^[0-9a-f]{64}$/D')
        ->and(ApiKey::hash($key))->not->toBe(hash('sha256', substr($key, strlen(ApiKey::PREFIX))));
});

it('accepts a valid key name', function (string $name) {
    expect(ApiKey::isValidName($name))->toBeTrue();
})->with([
    'plain' => 'sina',
    'dash' => 'local-dev',
    'all allowed characters' => 'a.b_c-1',
    '64 characters' => str_repeat('a', 64),
]);

it('refuses a bad key name', function (mixed $name) {
    expect(ApiKey::isValidName($name))->toBeFalse();
})->with([
    'empty' => '',
    '65 characters' => str_repeat('a', 65),
    'colon' => 'sina:x',
    'comma' => 'a,b',
    'space' => 'a b',
    'trailing newline' => "sina\n",
    'null' => null,
    'int' => 5,
    'array' => [['sina']],
]);
