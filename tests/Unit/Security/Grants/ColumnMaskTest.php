<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use SinaSaeedi\RuntimeMcp\Security\Grants\ColumnMask;
use SinaSaeedi\RuntimeMcp\Security\Grants\InvalidGrantConfig;

/** The spec 4.5 default patterns. */
function columnMaskDefaults(): ColumnMask
{
    return new ColumnMask(['password', 'remember_token', '*token*', '*secret*', '*api_key*']);
}

it('masks the columns that the default patterns name', function (string $column) {
    expect(columnMaskDefaults()->matches($column))->toBeTrue();
})->with([
    'password', 'remember_token', 'api_token', 'refresh_token', 'TokenHash', 'token',
    'client_secret', 'two_factor_secret', 'stripe_api_key', 'api_key',
]);

it('leaves the other columns readable', function (string $column) {
    expect(columnMaskDefaults()->matches($column))->toBeFalse();
})->with([
    'password_changed_at', 'old_password', 'id', 'name', 'email', 'created_at', 'tokn', 'apikey', 'secre',
]);

it('matches without regard to case', function () {
    $mask = columnMaskDefaults();

    expect($mask->matches('PASSWORD'))->toBeTrue()
        ->and($mask->matches('Remember_Token'))->toBeTrue()
        ->and($mask->matches('STRIPE_API_KEY'))->toBeTrue();
});

it('anchors a pattern at both ends', function () {
    $mask = new ColumnMask(['password', 'key*', '*hash']);

    expect($mask->matches('password'))->toBeTrue()
        ->and($mask->matches('password_changed_at'))->toBeFalse()
        ->and($mask->matches('old_password'))->toBeFalse()
        ->and($mask->matches('keys'))->toBeTrue()
        ->and($mask->matches('monkey'))->toBeFalse()
        ->and($mask->matches('token_hash'))->toBeTrue()
        ->and($mask->matches('hash_algo'))->toBeFalse();
});

it('treats every character but * as a literal', function () {
    $mask = new ColumnMask(['a.b', 'c+d', '(e)', 'f$']);

    expect($mask->matches('a.b'))->toBeTrue()
        ->and($mask->matches('axb'))->toBeFalse()
        ->and($mask->matches('c+d'))->toBeTrue()
        ->and($mask->matches('ccd'))->toBeFalse()
        ->and($mask->matches('(e)'))->toBeTrue()
        ->and($mask->matches('e'))->toBeFalse()
        ->and($mask->matches('f$'))->toBeTrue()
        ->and($mask->matches('f'))->toBeFalse();
});

it('does not let a column with a line break pass an anchored pattern', function () {
    expect((new ColumnMask(['password']))->matches("password\n"))->toBeFalse();
});

it('lets * match across a line break, so a line break cannot hide a column from the mask', function () {
    $mask = columnMaskDefaults();

    expect($mask->matches("api_token\nx"))->toBeTrue()
        ->and($mask->matches("x\ntoken"))->toBeTrue()
        ->and($mask->matches("a\nb_secret\r\nc"))->toBeTrue();
});

it('ignores an entry that is not a non-empty string, and keeps the usable ones as written', function () {
    $mask = new ColumnMask(['password', 123, '', null, ['x'], '*Token*']);

    expect($mask->patterns())->toBe(['password', '*Token*'])
        ->and($mask->ignored())->toBe([123, '', null, ['x']])
        ->and($mask->matches('123'))->toBeFalse()
        ->and($mask->matches('api_token'))->toBeTrue();
});

it('matches nothing with no pattern', function () {
    $mask = new ColumnMask([]);

    expect($mask->matches('password'))->toBeFalse()
        ->and($mask->patterns())->toBe([])
        ->and($mask->ignored())->toBe([]);
});

it('reads the list from the config', function () {
    $mask = ColumnMask::fromConfig(new Repository(['runtime-mcp' => ['database' => ['masked_columns' => ['ssn']]]]));

    expect($mask->patterns())->toBe(['ssn'])
        ->and($mask->matches('SSN'))->toBeTrue();
});

it('refuses a masked_columns value that is not an array', function (mixed $value) {
    $thrown = null;

    try {
        ColumnMask::fromConfig(new Repository(['runtime-mcp' => ['database' => ['masked_columns' => $value]]]));
    } catch (InvalidGrantConfig $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(InvalidGrantConfig::class)
        ->and($thrown?->key)->toBe('masked_columns')
        ->and($thrown?->getMessage())->toBe('runtime-mcp.database.masked_columns must be an array of names. No role SQL was generated.');
})->with([
    'a string' => ['password'],
    'null' => [null],
    'a number' => [1],
]);

it('refuses a missing masked_columns key', function () {
    expect(fn () => ColumnMask::fromConfig(new Repository([])))->toThrow(InvalidGrantConfig::class);
});
