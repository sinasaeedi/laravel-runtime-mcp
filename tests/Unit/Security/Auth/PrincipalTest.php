<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use SinaSaeedi\RuntimeMcp\Principal;
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

it('has the audit values api_key and stdio', function () {
    expect(array_map(fn (AuthMethod $method): string => $method->value, AuthMethod::cases()))->toBe(['api_key', 'stdio']);
});
