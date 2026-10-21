<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use SinaSaeedi\RuntimeMcp\Principal;
use SinaSaeedi\RuntimeMcp\Security\Auth\ApiKey;
use SinaSaeedi\RuntimeMcp\Security\Auth\AuthMethod;

it('is the Gate subject when no user is linked', function () {
    $principal = new Principal('sina', AuthMethod::ApiKey);

    expect($principal->subject())->toBe($principal)
        ->and($principal->user)->toBeNull()
        ->and($principal->userId())->toBeNull();
});

it('makes the linked user the Gate subject', function () {
    $user = new GenericUser(['id' => 7]);
    $principal = new Principal('sina', AuthMethod::ApiKey, $user);

    expect($principal->subject())->toBe($user)
        ->and($principal->userId())->toBe(7);
});

it('gives the user id only when it is an int or a string', function (mixed $id, int|string|null $expected) {
    expect((new Principal('sina', AuthMethod::ApiKey, new GenericUser(['id' => $id])))->userId())->toBe($expected);
})->with([
    'int' => [7, 7],
    'string' => ['01J9', '01J9'],
    'null' => [null, null],
    'array' => [['x'], null],
]);

it('is not a user, and cannot change', function () {
    $class = new ReflectionClass(Principal::class);

    expect($class->implementsInterface(Authenticatable::class))->toBeFalse()
        ->and($class->isReadOnly())->toBeTrue()
        ->and($class->isFinal())->toBeTrue();
});

it('has the audit values api_key, stdio and sanctum', function () {
    expect(array_map(fn (AuthMethod $method): string => $method->value, AuthMethod::cases()))->toBe(['api_key', 'stdio', 'sanctum']);
});

// 01-35: the Sanctum principal (and 01-39's asUser()) name a user "user-<id>" in the key-name format.
it('names a user principal user-<id>, in the format of a key name', function (int|string $id, string $name) {
    expect(Principal::userName($id))->toBe($name)
        ->and(ApiKey::isValidName(Principal::userName($id)))->toBeTrue();
})->with([
    'int' => [42, 'user-42'],
    'uuid' => ['9d6e2f1a-3b4c-4d5e-8f90-a1b2c3d4e5f6', 'user-9d6e2f1a-3b4c-4d5e-8f90-a1b2c3d4e5f6'],
    'ulid' => ['01J9ZX4K8E5WQ3T6V7Y8B9C0DF', 'user-01J9ZX4K8E5WQ3T6V7Y8B9C0DF'],
    'other characters' => ['a@b c/d|e', 'user-a-b-c-d-e'],
    'long' => [str_repeat('9', 70), 'user-'.str_repeat('9', 59)],
    'a key-like id' => ['rmcp_x', 'user-rmcp_x'],
]);

it('cuts a user principal name to 64 characters', function () {
    expect(strlen(Principal::userName(str_repeat('é', 100))))->toBe(64)
        ->and(Principal::USER_PREFIX)->toBe('user-');
});
