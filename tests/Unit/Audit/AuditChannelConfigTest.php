<?php

declare(strict_types=1);

use Illuminate\Config\Repository as ConfigRepository;
use SinaSaeedi\RuntimeMcp\Audit\AuditChannel;

function auditChannelConfig(mixed $channel, mixed $days): ConfigRepository
{
    return new ConfigRepository(['runtime-mcp' => ['audit' => ['channel' => $channel, 'days' => $days]]]);
}

it('takes a valid channel name', function (string $name) {
    expect(AuditChannel::name(auditChannelConfig($name, 90)))->toBe($name);
})->with([
    'the default' => ['runtime-mcp'],
    'another name' => ['security'],
    'digits, underscore, dash' => ['audit_2-eu'],
    '64 characters' => [str_repeat('a', 64)],
]);

// Rule 12: a bad value never turns the audit off, it falls back to the default channel.
it('falls back to runtime-mcp for a bad channel name', function (mixed $name) {
    expect(AuditChannel::name(auditChannelConfig($name, 90)))->toBe('runtime-mcp');
})->with([
    'null' => [null],
    'empty string' => [''],
    'a dot (nested config key)' => ['audit.security'],
    'a space' => ['audit log'],
    'a slash' => ['../audit'],
    '65 characters' => [str_repeat('a', 65)],
    'an array' => [['runtime-mcp']],
    'an int' => [5],
    'false' => [false],
]);

it('takes a valid retention', function (mixed $days, int $expected) {
    expect(AuditChannel::days(auditChannelConfig('runtime-mcp', $days)))->toBe($expected);
})->with([
    'the default' => [90, 90],
    'one day' => [1, 1],
    'an int' => [30, 30],
    'an env() string' => ['30', 30],
    'a long retention' => [3650, 3650],
]);

// Monolog reads 0 as "keep every file forever", so 0 is never passed on.
it('falls back to 90 days for a bad retention', function (mixed $days) {
    expect(AuditChannel::days(auditChannelConfig('runtime-mcp', $days)))->toBe(90);
})->with([
    'zero' => [0],
    'a zero string' => ['0'],
    'negative' => [-5],
    'a negative string' => ['-5'],
    'not a number' => ['abc'],
    'a float' => [30.5],
    'a float string' => ['30.5'],
    'null' => [null],
    'an array' => [[30]],
    'true' => [true],
    'seven digits' => ['1000000'],
]);
