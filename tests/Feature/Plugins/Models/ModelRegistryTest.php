<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Plugins\Models\ModelRegistry;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Models\AbstractRecord;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Models\Order;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Models\OrderStatus;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Models\RegistryProbe;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Models\User;

/*
 * 01-21 Part A: the `models` config. A valid entry reaches model_show; an invalid one is left out (fail
 * closed for that alias) with a reason for doctor. The registry never throws.
 */

/** @param  mixed  $models  the runtime-mcp.models value */
function modelRegistry(mixed $models): ModelRegistry
{
    config()->set('runtime-mcp.models', $models);

    return app(ModelRegistry::class);
}

it('MR1 keeps a valid entry, with defaults for relations and appends, and drops duplicate names', function () {
    $registry = modelRegistry([
        'order' => ['class' => Order::class, 'relations' => ['items', 'customer', 'items'], 'appends' => ['display_total']],
        'customer' => ['class' => User::class],
    ]);

    expect($registry->entries())->toBe([
        'order' => ['class' => Order::class, 'relations' => ['items', 'customer'], 'appends' => ['display_total']],
        'customer' => ['class' => User::class, 'relations' => [], 'appends' => []],
    ])
        ->and($registry->invalid())->toBe([])
        ->and($registry->aliases())->toBe(['order', 'customer'])
        ->and($registry->entry('order')['class'] ?? null)->toBe(Order::class)
        ->and($registry->entry('nope'))->toBeNull();
});

it('MR2 gives every relation name of every valid entry once, sorted', function () {
    $registry = modelRegistry([
        'order' => ['class' => Order::class, 'relations' => ['tags', 'items']],
        'customer' => ['class' => User::class, 'relations' => ['orders', 'items']],
        'bad' => ['class' => 'Nope', 'relations' => ['zzz']],
    ]);

    expect($registry->relationUnion())->toBe(['items', 'orders', 'tags']);
});

it('MR3 has no entry when models is not an array, or is empty', function (mixed $models) {
    $registry = modelRegistry($models);

    expect($registry->entries())->toBe([])
        ->and($registry->invalid())->toBe([])
        ->and($registry->relationUnion())->toBe([]);
})->with([
    'null' => [null],
    'a string' => ['order'],
    'empty' => [[]],
]);

it('MR4 leaves out an entry with a bad alias, shape or class, and says why', function (int|string $alias, mixed $entry, string $reason) {
    $registry = modelRegistry([$alias => $entry, 'order' => ['class' => Order::class]]);

    expect($registry->aliases())->toBe(['order'])
        ->and($registry->invalid())->toBe([(string) $alias => $reason]);
})->with([
    'upper case alias' => ['Order', ['class' => Order::class], 'the alias must be lower snake case: a letter, then letters, digits or underscores, at most 64 characters.'],
    'alias starts with a digit' => ['1order', ['class' => Order::class], 'the alias must be lower snake case: a letter, then letters, digits or underscores, at most 64 characters.'],
    'alias of 65 characters' => [str_repeat('a', 65), ['class' => Order::class], 'the alias must be lower snake case: a letter, then letters, digits or underscores, at most 64 characters.'],
    'an int key' => [7, ['class' => Order::class], 'the alias must be lower snake case: a letter, then letters, digits or underscores, at most 64 characters.'],
    'not an array' => ['thing', Order::class, 'the entry is not an array with a `class` key.'],
    'an unknown key' => ['thing', ['class' => Order::class, 'relation' => ['items']], 'the entry has an unknown key `relation` (allowed: class, relations, appends).'],
    'no class' => ['thing', ['relations' => []], 'the class is not a concrete Eloquent model class that can be loaded.'],
    'a missing class' => ['thing', ['class' => 'App\Models\Nope'], 'the class is not a concrete Eloquent model class that can be loaded.'],
    'not a model' => ['thing', ['class' => OrderStatus::class], 'the class is not a concrete Eloquent model class that can be loaded.'],
    'an abstract model' => ['thing', ['class' => AbstractRecord::class], 'the class is not a concrete Eloquent model class that can be loaded.'],
    'relations not a list' => ['thing', ['class' => Order::class, 'relations' => ['a' => 'items']], '`relations` is not a list of names.'],
    'appends a string' => ['thing', ['class' => Order::class, 'appends' => 'display_total'], '`appends` is not a list of names.'],
    'a relation that is not a name' => ['thing', ['class' => Order::class, 'relations' => ['items; drop']], '`relations` holds a value that is not a method or attribute name.'],
    'an append that is not a string' => ['thing', ['class' => Order::class, 'appends' => [1]], '`appends` holds a value that is not a method or attribute name.'],
]);

it('MR5 refuses a relation name that is a method of Model itself or of a Laravel trait, or not a public instance method without arguments', function (string $name) {
    $registry = modelRegistry(['probe' => ['class' => RegistryProbe::class, 'relations' => ['items', $name]]]);

    expect($registry->entries())->toBe([])
        ->and($registry->invalid()['probe'] ?? null)->toBe("the relation `{$name}` is not a public method of ".RegistryProbe::class." itself (a method of Eloquent's Model or of a Laravel trait, such as delete or restore, is never a relation).");
})->with([
    'delete' => ['delete'],
    'Delete (PHP method names ignore case)' => ['Delete'],
    'forceDelete' => ['forceDelete'],
    'save' => ['save'],
    'push' => ['push'],
    'touch' => ['touch'],
    'SoftDeletes::restore (writes)' => ['restore'],
    'SoftDeletes::restoreQuietly (writes)' => ['restoreQuietly'],
    'MassPrunable::pruneAll (deletes)' => ['pruneAll'],
    'MustVerifyEmail::markEmailAsVerified (writes)' => ['markEmailAsVerified'],
    'MustVerifyEmail::sendEmailVerificationNotification (sends mail)' => ['sendEmailVerificationNotification'],
    'Notifiable::notifications (a relation, but Laravel\'s method)' => ['notifications'],
    'a missing method' => ['nothing'],
    'a static method' => ['staticOne'],
    'a protected method' => ['hiddenOne'],
    'a method with a required argument' => ['withArgument'],
]);

it('MR6 keeps a public method of the app class that may not return a relation (model_show and doctor find that out), and one that wraps a Laravel trait\'s relation', function () {
    expect(modelRegistry(['probe' => ['class' => RegistryProbe::class, 'relations' => ['notARelation', 'broken', 'inbox']]])->aliases())
        ->toBe(['probe']);
});

it('MR7 never throws for an app class that fails to load, and reads the config again on each call', function () {
    $loader = function (string $class): void {
        if ($class === 'App\Models\Broken') {
            throw new ParseError('syntax error, unexpected end of file');
        }
    };
    spl_autoload_register($loader);

    try {
        $registry = modelRegistry(['broken' => ['class' => 'App\Models\Broken'], 'order' => ['class' => Order::class]]);

        expect($registry->aliases())->toBe(['order'])
            ->and($registry->invalid())->toBe(['broken' => 'the class is not a concrete Eloquent model class that can be loaded.'])
            ->and(ModelRegistry::isModelClass('App\Models\Broken'))->toBeFalse();

        config()->set('runtime-mcp.models', []);

        expect($registry->aliases())->toBe([]);
    } finally {
        spl_autoload_unregister($loader);
    }
});
