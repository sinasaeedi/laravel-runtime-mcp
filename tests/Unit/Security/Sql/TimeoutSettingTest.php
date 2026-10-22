<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use SinaSaeedi\RuntimeMcp\Security\Sql\TimeoutSetting;

/* Spec 4.5, 5.2, 01-14 Part C: database.statement_timeout_ms. A bad value gives 5000, never 0. */

function timeoutSettingFor(mixed $value): TimeoutSetting
{
    return new TimeoutSetting(new Repository(['runtime-mcp' => ['database' => ['statement_timeout_ms' => $value]]]));
}

it('S1 uses a valid value: an int from 1 to the PostgreSQL limit, or a string of digits', function (mixed $value, int $milliseconds) {
    $setting = timeoutSettingFor($value);

    expect($setting->isValid())->toBeTrue()
        ->and($setting->milliseconds())->toBe($milliseconds);
})->with([
    'the default' => [5000, 5000],
    'one' => [1, 1],
    'an env() string' => ['2500', 2500],
    'the limit' => [2_147_483_647, 2_147_483_647],
    'the limit as a string' => ['2147483647', 2_147_483_647],
]);

it('S2 uses 5000 for any other value, never 0', function (mixed $value) {
    $setting = timeoutSettingFor($value);

    expect($setting->isValid())->toBeFalse()
        ->and($setting->milliseconds())->toBe(5000)
        ->and(TimeoutSetting::DEFAULT_MS)->toBe(5000);
})->with([
    'zero' => [0],
    'negative' => [-1],
    'zero as a string' => ['0'],
    'over the limit' => [2_147_483_648],
    'over the limit as a string' => ['2147483648'],
    'too many digits' => ['99999999999999999999'],
    'a float' => [2.5],
    'a sign' => ['+500'],
    'a space' => [' 500'],
    'a word' => ['abc'],
    'empty' => [''],
    'null' => [null],
    'true' => [true],
    'an array' => [[5000]],
]);

it('S3 reads the config on every call, never a cached value (rule 14)', function () {
    $config = new Repository(['runtime-mcp' => ['database' => ['statement_timeout_ms' => 700]]]);
    $setting = new TimeoutSetting($config);

    expect($setting->milliseconds())->toBe(700);

    $config->set('runtime-mcp.database.statement_timeout_ms', 900);

    expect($setting->milliseconds())->toBe(900);
});
