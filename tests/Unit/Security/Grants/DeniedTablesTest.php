<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use SinaSaeedi\RuntimeMcp\Security\Grants\DeniedTables;
use SinaSaeedi\RuntimeMcp\Security\Grants\InvalidGrantConfig;

it('denies a table by its exact name, in any schema', function () {
    $denied = new DeniedTables(['sessions', 'jobs']);

    expect($denied->contains('public', 'sessions'))->toBeTrue()
        ->and($denied->contains('audit', 'sessions'))->toBeTrue()
        ->and($denied->contains('runtime_mcp', 'jobs'))->toBeTrue()
        ->and($denied->contains('public', 'job_batches'))->toBeFalse()
        ->and($denied->contains('public', 'failed_jobs'))->toBeFalse()
        ->and($denied->contains('public', 'sessions_archive'))->toBeFalse()
        ->and($denied->contains('public', 'user_sessions'))->toBeFalse();
});

it('matches without regard to case', function () {
    $denied = new DeniedTables(['Sessions', 'Audit.Log']);

    expect($denied->contains('public', 'SESSIONS'))->toBeTrue()
        ->and($denied->contains('public', 'sessions'))->toBeTrue()
        ->and($denied->contains('AUDIT', 'log'))->toBeTrue();
});

it('denies a schema-qualified entry in that schema only', function () {
    $denied = new DeniedTables(['audit.sessions']);

    expect($denied->contains('audit', 'sessions'))->toBeTrue()
        ->and($denied->contains('public', 'sessions'))->toBeFalse();
});

it('denies the prefixed name and the name without the prefix', function () {
    $denied = new DeniedTables(['sessions', 'audit.log']);

    expect($denied->contains('public', 'pfx_sessions', 'pfx_'))->toBeTrue()
        ->and($denied->contains('public', 'PFX_Sessions', 'pfx_'))->toBeTrue()
        ->and($denied->contains('public', 'sessions', 'pfx_'))->toBeTrue()
        ->and($denied->contains('audit', 'pfx_log', 'pfx_'))->toBeTrue()
        ->and($denied->contains('public', 'pfx_log', 'pfx_'))->toBeFalse()
        ->and($denied->contains('public', 'pfx_widgets', 'pfx_'))->toBeFalse();
});

it('strips the prefix only when the name starts with it', function () {
    $denied = new DeniedTables(['sessions']);

    // "x_pfx_sessions" holds the prefix, but not at the start.
    expect($denied->contains('public', 'x_pfx_sessions', 'pfx_'))->toBeFalse()
        ->and($denied->contains('public', 'app_sessions', 'pfx_'))->toBeFalse()
        ->and($denied->contains('public', 'sessions_pfx_', 'pfx_'))->toBeFalse()
        ->and($denied->contains('public', 'pfx_sessions'))->toBeFalse();
});

it('ignores an entry that is not a non-empty string or has more than one dot', function () {
    $denied = new DeniedTables(['sessions', 'a.b.c', '', 42, null, 'audit.log']);

    expect($denied->ignored())->toBe(['a.b.c', '', 42, null])
        ->and($denied->contains('a', 'b.c'))->toBeFalse()
        ->and($denied->contains('a.b', 'c'))->toBeFalse()
        ->and($denied->contains('public', 'sessions'))->toBeTrue()
        ->and($denied->contains('audit', 'log'))->toBeTrue();
});

it('reads the list from the config', function () {
    $denied = DeniedTables::fromConfig(new Repository(['runtime-mcp' => ['database' => ['denied_tables' => ['cache']]]]));

    expect($denied->contains('public', 'cache'))->toBeTrue()
        ->and($denied->ignored())->toBe([]);
});

it('refuses a denied_tables value that is not an array', function (mixed $value) {
    $thrown = null;

    try {
        DeniedTables::fromConfig(new Repository(['runtime-mcp' => ['database' => ['denied_tables' => $value]]]));
    } catch (InvalidGrantConfig $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(InvalidGrantConfig::class)
        ->and($thrown?->key)->toBe('denied_tables')
        ->and($thrown?->getMessage())->toBe('runtime-mcp.database.denied_tables must be an array of names. No role SQL was generated.');
})->with([
    'null' => [null],
    'a string' => ['sessions'],
    'false' => [false],
]);

it('ignores an entry with an empty part before or after the dot, so doctor reports it', function () {
    // Each one can match no table. Kept silently, the table it meant would be granted with no warning.
    $denied = new DeniedTables(['.sessions', 'sessions.', 'public.', '.', 'audit.log']);

    expect($denied->ignored())->toBe(['.sessions', 'sessions.', 'public.', '.'])
        ->and($denied->contains('audit', 'log'))->toBeTrue();
});
