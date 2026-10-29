<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Tests\Support\SourceFiles;
use SinaSaeedi\RuntimeMcp\Tests\Support\SourceScanner;

// Spec rule 2: the query runner does not parse SQL, check leading keywords, or keep a blocklist. Pin it
// against a "small helpful check" later: no string search or split function anywhere in src/Security/Sql/.
// The allow-list is empty. Reading a database error message (01-20) lives in src/Plugins/Database/.
const NO_SQL_PARSING_FUNCTIONS = [
    'preg_match', 'preg_match_all', 'preg_split', 'preg_replace', 'preg_replace_callback', 'preg_grep',
    'stripos', 'strpos', 'strrpos', 'strripos', 'stristr', 'strstr', 'strrchr', 'strpbrk',
    'str_contains', 'str_starts_with', 'str_ends_with',
    'strcmp', 'strcasecmp', 'strncmp', 'strncasecmp', 'substr_compare', 'substr_count', 'strspn', 'strcspn',
    'explode', 'strtok', 'str_split', 'substr', 'sscanf', 'strtolower', 'strtoupper',
    'mb_stripos', 'mb_strpos', 'mb_strrpos', 'mb_strripos', 'mb_stristr', 'mb_strstr', 'mb_strrchr',
    'mb_str_split', 'mb_substr', 'mb_strtolower', 'mb_strtoupper', 'mb_ereg', 'mb_eregi', 'mb_split',
    // Laravel's helper: str($sql)->startsWith('DELETE') (Str and Stringable are banned below).
    'str',
];

test('src/Security/Sql/ calls no string search or split function (rule 2)', function () {
    $files = array_values(array_filter(
        SourceFiles::paths(),
        static fn (string $path): bool => str_contains($path, '/src/Security/Sql/'),
    ));

    expect(count($files))->toBeGreaterThanOrEqual(12)
        ->and(SourceScanner::bannedCalls($files, NO_SQL_PARSING_FUNCTIONS))->toBe([]);
});

// The scan above skips static calls, so Str::startsWith($sql, 'DELETE') needs this rule.
arch('src/Security/Sql/ uses no Laravel string helper class (rule 2)', function () {
    expect('SinaSaeedi\RuntimeMcp\Security\Sql')->not->toUse(['Illuminate\Support\Str', 'Illuminate\Support\Stringable']);
});

// Pest's arch check is silently green for a name that resolves to nothing.
test('the banned string helper classes exist', function () {
    expect(class_exists('Illuminate\Support\Str'))->toBeTrue()
        ->and(class_exists('Illuminate\Support\Stringable'))->toBeTrue();
});
