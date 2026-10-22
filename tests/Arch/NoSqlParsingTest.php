<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Tests\Support\SourceFiles;
use SinaSaeedi\RuntimeMcp\Tests\Support\SourceScanner;

// Spec rule 2: the query runner does not parse SQL, check leading keywords, or keep a blocklist. Pin it
// against a "small helpful check" later: no string search or split function anywhere in src/Security/Sql/.
// The allow-list is empty. Reading a database error message (01-20) lives in src/Plugins/Database/.
const NO_SQL_PARSING_FUNCTIONS = [
    'preg_match', 'preg_match_all', 'preg_split', 'preg_replace', 'preg_replace_callback',
    'stripos', 'strpos', 'stristr', 'strstr', 'str_contains', 'str_starts_with', 'str_ends_with',
    'explode', 'strtok', 'substr', 'strtolower', 'strtoupper',
];

test('src/Security/Sql/ calls no string search or split function (rule 2)', function () {
    $files = array_values(array_filter(
        SourceFiles::paths(),
        static fn (string $path): bool => str_contains($path, '/src/Security/Sql/'),
    ));

    expect(count($files))->toBeGreaterThanOrEqual(12)
        ->and(SourceScanner::bannedCalls($files, NO_SQL_PARSING_FUNCTIONS))->toBe([]);
});
