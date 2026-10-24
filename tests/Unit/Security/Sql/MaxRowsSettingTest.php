<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use SinaSaeedi\RuntimeMcp\Security\Sql\MaxRowsSetting;

/* Spec 4.5, 5.8, 01-15 Part A: database.max_rows. A bad value gives 500, never 0. */

function maxRowsSettingFor(mixed $value): MaxRowsSetting
{
    return new MaxRowsSetting(new Repository(['runtime-mcp' => ['database' => ['max_rows' => $value]]]));
}

it('QS1 uses a valid value: an int of 1 or more, or a string of digits', function (mixed $value, int $rows) {
    expect(maxRowsSettingFor($value)->rows())->toBe($rows);
})->with([
    'the default' => [500, 500],
    'one' => [1, 1],
    'an env() string' => ['50', 50],
    'large' => [100_000, 100_000],
]);

it('QS2 uses 500 for any other value, never 0', function (mixed $value) {
    expect(maxRowsSettingFor($value)->rows())->toBe(500)
        ->and(MaxRowsSetting::DEFAULT)->toBe(500);
})->with([
    'zero' => [0],
    'negative' => [-1],
    'zero as a string' => ['0'],
    'PHP_INT_MAX (the runner adds 1)' => [PHP_INT_MAX],
    'too many digits' => ['99999999999999999999'],
    'a word' => ['abc'],
    'a float' => [12.5],
    'a signed string' => ['+50'],
    'null' => [null],
    'an array' => [[500]],
]);
