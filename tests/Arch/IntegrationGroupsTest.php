<?php

declare(strict_types=1);

// CI runs tests/Integration only through the pgsql and mysql groups, and tests/Pest.php binds each
// group to one folder. A test file anywhere else in tests/Integration would run in no CI job, and CI
// would still be green. A new folder needs its own group in tests/Pest.php and its own CI step first.
test('every integration test sits in a folder that CI runs as a group', function () {
    $root = dirname(__DIR__).'/Integration/';
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file instanceof SplFileInfo && str_ends_with($file->getFilename(), 'Test.php')) {
            $files[] = substr($file->getPathname(), strlen($root));
        }
    }

    $outside = array_values(array_filter(
        $files,
        static fn (string $path): bool => ! str_starts_with($path, 'Pgsql/') && ! str_starts_with($path, 'Mysql/'),
    ));

    expect($files)->not->toBeEmpty()
        ->and($outside)->toBe([]);
});
