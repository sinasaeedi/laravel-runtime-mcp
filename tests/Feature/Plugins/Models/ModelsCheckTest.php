<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use SinaSaeedi\RuntimeMcp\Console\DoctorCommand;
use SinaSaeedi\RuntimeMcp\Doctor\CheckResult;
use SinaSaeedi\RuntimeMcp\Plugins\Models\Doctor\ModelsCheck;
use SinaSaeedi\RuntimeMcp\Plugins\Models\ModelsPlugin;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Models\EncryptedProfile;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Models\Invoice;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Models\Note;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Models\Order;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Models\RegistryProbe;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Models\ThrowingModel;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Models\User;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Models\VisibleProfile;

/*
 * 01-21 Part E (spec 6.2, 7.3): the `Models` doctor check of the models plugin. It builds models and
 * relations with `new` and never queries, so SQLite is enough. `billing` is configured; `nowhere` is not.
 */

/**
 * @param  array<string, mixed>  $config
 * @return list<array{string, string, string|null}>
 */
function modelsCheckResults(mixed $models, array $config = []): array
{
    currentTest()->bootWith([
        'runtime-mcp.models' => $models,
        'database.connections.billing' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        ...$config,
    ]);

    $checks = app(ModelsPlugin::class)->doctorChecks();

    expect($checks)->toHaveCount(1)
        ->and($checks[0])->toBeInstanceOf(ModelsCheck::class)
        ->and($checks[0]->title())->toBe('Models');

    return array_map(
        fn (CheckResult $result): array => [$result->status->value, $result->message, $result->fix],
        $checks[0]->run(),
    );
}

it('MC1 passes with one line when no model is allow-listed', function () {
    expect(modelsCheckResults([]))->toBe([['pass', 'No model is allow-listed.', null]]);
});

it('MC2 passes a good entry with its class and counts, and is silent on a MorphTo', function () {
    expect(modelsCheckResults([
        'order' => ['class' => Order::class, 'relations' => ['items', 'customer', 'tags', 'notes', 'latestNote', 'labels'], 'appends' => ['display_total']],
        'customer' => ['class' => User::class, 'relations' => ['orders', 'latestOrder', 'items', 'firstItem']],
        'note' => ['class' => Note::class, 'relations' => ['notable']],
    ]))->toBe([
        ['pass', '`order`: '.Order::class.', 6 relation(s), 1 append(s).', null],
        ['pass', '`customer`: '.User::class.', 4 relation(s), 0 append(s).', null],
        ['pass', '`note`: '.Note::class.', 1 relation(s), 0 append(s).', null],
    ]);
});

it('MC3 fails an invalid entry, by alias when it is name-shaped and by position when not', function () {
    expect(modelsCheckResults([
        'Order' => ['class' => Order::class],
        'has space' => ['class' => Order::class],
        'gone' => ['class' => 'App\Models\Gone'],
    ]))->toBe([
        ['fail', '`Order` is left out of model_show: the alias must be lower snake case: a letter, then letters, digits or underscores, at most 64 characters.', 'Fix the entry in runtime-mcp.models.'],
        ['fail', 'The entry at position 2 is left out of model_show: the alias must be lower snake case: a letter, then letters, digits or underscores, at most 64 characters.', 'Fix the entry in runtime-mcp.models.'],
        ['fail', '`gone` is left out of model_show: the class is not a concrete Eloquent model class that can be loaded.', 'Fix the entry in runtime-mcp.models.'],
    ]);

    // The position counts every entry of the config, the valid ones too (01-21 triage).
    expect(modelsCheckResults([
        'order' => ['class' => Order::class],
        'has space' => ['class' => Order::class],
    ]))->toBe([
        ['fail', 'The entry at position 2 is left out of model_show: the alias must be lower snake case: a letter, then letters, digits or underscores, at most 64 characters.', 'Fix the entry in runtime-mcp.models.'],
        ['pass', '`order`: '.Order::class.', 0 relation(s), 0 append(s).', null],
    ]);
});

it('MC4 warns about a model and a relation on their own connection, and fails a relation that cannot be built', function () {
    expect(modelsCheckResults([
        'invoice' => ['class' => Invoice::class],
        'order' => ['class' => Order::class, 'relations' => ['invoices', 'ledger', 'items']],
    ]))->toBe([
        ['warn', 'model_show reads `invoice` on the read-only connection, not on `billing`.', 'Leave `invoice` out of runtime-mcp.models, or make sure the read-only connection holds its table.'],
        ['warn', 'Relation `order`.`invoices` uses connection `billing` ('.Invoice::class.') and will not be loaded.', 'Remove `invoices` from the relations of `order`, or remove the model\'s own $connection.'],
        ['fail', 'Relation `order`.`ledger` could not be built (InvalidArgumentException).', 'Remove `ledger` from the relations of `order`.'],
    ]);
});

it('MC5 fails a relation that is not a relation, throws, or is a class Laravel does not ship', function () {
    expect(modelsCheckResults([
        'probe' => ['class' => RegistryProbe::class, 'relations' => ['items', 'notARelation', 'broken']],
        'order' => ['class' => Order::class, 'relations' => ['custom']],
    ]))->toBe([
        ['fail', 'Relation `probe`.`notARelation` does not return an Eloquent relation.', 'Remove `notARelation` from the relations of `probe`.'],
        ['fail', 'Relation `probe`.`broken` could not be built (RuntimeException).', 'Remove `broken` from the relations of `probe`.'],
        ['fail', 'Relation `order`.`custom` is a CustomHasMany, which model_show does not load.', 'Remove `custom` from the relations of `order`.'],
    ]);
});

it('MC6 warns about each encrypted cast that neither $hidden, $visible nor masked_columns hides', function () {
    expect(modelsCheckResults([
        'profile' => ['class' => EncryptedProfile::class],
        'visible' => ['class' => VisibleProfile::class],
    ]))->toBe([
        ['warn', 'model_show returns casts applied, so `profile`.`ssn` would be returned decrypted.', 'Add `ssn` to the model\'s $hidden, or to runtime-mcp.database.masked_columns.'],
        ['warn', 'model_show returns casts applied, so `profile`.`prefs` would be returned decrypted.', 'Add `prefs` to the model\'s $hidden, or to runtime-mcp.database.masked_columns.'],
        ['warn', 'model_show returns casts applied, so `profile`.`history` would be returned decrypted.', 'Add `history` to the model\'s $hidden, or to runtime-mcp.database.masked_columns.'],
        ['warn', 'model_show returns casts applied, so `profile`.`wallet` would be returned decrypted.', 'Add `wallet` to the model\'s $hidden, or to runtime-mcp.database.masked_columns.'],
        ['warn', 'model_show returns casts applied, so `visible`.`nickname` would be returned decrypted.', 'Add `nickname` to the model\'s $hidden, or to runtime-mcp.database.masked_columns.'],
    ]);
});

it('MC7 warns about every encrypted cast when masked_columns is not a list (it masks nothing)', function () {
    $messages = array_column(modelsCheckResults(['profile' => ['class' => EncryptedProfile::class]], ['runtime-mcp.database.masked_columns' => 'oops']), 1);

    expect($messages)->toContain('model_show returns casts applied, so `profile`.`api_secret` would be returned decrypted.')
        ->and($messages)->toHaveCount(5);
});

it('MC8 reports a model that cannot be built, and still checks the next alias', function () {
    expect(modelsCheckResults([
        'broken' => ['class' => ThrowingModel::class],
        'customer' => ['class' => User::class],
    ]))->toBe([
        ['fail', '`broken` could not be checked (RuntimeException).', 'Run php artisan runtime-mcp:doctor -v, or read the app log.'],
        ['pass', '`customer`: '.User::class.', 0 relation(s), 0 append(s).', null],
    ]);
});

it('MC9 is part of the doctor report while the models plugin is on, and gone when it is off', function () {
    currentTest()->bootWith(['runtime-mcp.models' => ['order' => ['class' => Order::class, 'relations' => ['ledger']]]]);
    Artisan::call(DoctorCommand::NAME);
    $on = Artisan::output();

    currentTest()->bootWith(['runtime-mcp.plugins.models' => false, 'runtime-mcp.models' => ['order' => ['class' => Order::class, 'relations' => ['ledger']]]]);
    Artisan::call(DoctorCommand::NAME);
    $off = Artisan::output();

    expect($on)->toContain('Relation `order`.`ledger` could not be built (InvalidArgumentException).')
        ->and(str_contains($off, 'Relation `order`.`ledger`'))->toBeFalse();
});
