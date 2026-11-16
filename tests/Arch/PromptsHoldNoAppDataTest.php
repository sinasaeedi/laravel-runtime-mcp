<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Tests\Support\SourceFiles;
use SinaSaeedi\RuntimeMcp\Tests\Support\SourceScanner;

/*
 * Spec rule 18, 11.2 "Prompts": a package prompt never reads the database, the logs, the queues, the
 * files or the cache. Two halves, because each misses what the other finds (the 01-36 spike probed it):
 * Pest's toOnlyUse() fails on a Laravel helper (`app`, `config`) or a class outside the allow-list, but
 * it ignores PHP's own functions and classes (`file_get_contents()`, `SplFileObject` passed); the token
 * scan finds those.
 */

/** @return list<string> the files of every class under a plugin Prompts namespace */
function promptsHoldNoAppDataFiles(): array
{
    return array_values(array_filter(SourceFiles::paths(), fn (string $path): bool => preg_match('#/src/Plugins/[A-Za-z]+/Prompts/[^/]+\.php$#', $path) === 1));
}

/** @return list<string> every plugin Prompts namespace */
function promptsHoldNoAppDataNamespaces(): array
{
    $namespaces = [];

    foreach (SourceFiles::classNames() as $class) {
        if (preg_match('/^(SinaSaeedi\\\\RuntimeMcp\\\\Plugins\\\\[A-Za-z]+\\\\Prompts)\\\\/', $class, $match) === 1) {
            $namespaces[$match[1]] = true;
        }
    }

    return array_keys($namespaces);
}

/** PHP's own functions that read a file, the environment, a socket or a process. */
const PROMPTS_HOLD_NO_APP_DATA_FUNCTIONS = [
    'file_get_contents', 'file', 'fopen', 'readfile', 'fread', 'fgets', 'fgetcsv', 'fscanf', 'stream_get_contents',
    'file_exists', 'is_file', 'is_readable', 'filesize', 'glob', 'scandir', 'opendir', 'readdir', 'parse_ini_file',
    'getenv', 'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'curl_init', 'fsockopen',
    'mysqli_connect', 'pg_connect', 'pg_query', 'apcu_fetch', 'unserialize',
];

/** PHP's own classes that reach a database, a file or a cache. */
const PROMPTS_HOLD_NO_APP_DATA_CLASSES = [
    'PDO', 'mysqli', 'SQLite3', 'SplFileObject', 'SplFileInfo', 'SplTempFileObject', 'DirectoryIterator',
    'FilesystemIterator', 'RecursiveDirectoryIterator', 'GlobIterator', 'Redis', 'Memcached', 'finfo',
];

arch('PN1 lets a plugin prompt use only the prompt contract and Str', function () {
    expect(promptsHoldNoAppDataNamespaces())->toOnlyUse(['SinaSaeedi\RuntimeMcp\Prompts', 'Illuminate\Support\Str']);
});

test('PN2 finds no call of a PHP file, environment, process or database function in a plugin prompt', function () {
    expect(SourceScanner::bannedCalls(promptsHoldNoAppDataFiles(), PROMPTS_HOLD_NO_APP_DATA_FUNCTIONS))->toBe([]);
});

test('PN3 finds no PHP database, file or cache class in a plugin prompt', function () {
    expect(SourceScanner::bannedClasses(promptsHoldNoAppDataFiles(), PROMPTS_HOLD_NO_APP_DATA_CLASSES))->toBe([]);
});

test('PN4 finds no superglobal in a plugin prompt', function () {
    $found = [];

    foreach (promptsHoldNoAppDataFiles() as $file) {
        $code = (string) file_get_contents($file);

        foreach (['$_ENV', '$_SERVER', '$GLOBALS', '$_COOKIE', '$_SESSION', '$_REQUEST', '$_GET', '$_POST', '$_FILES'] as $global) {
            if (str_contains($code, $global)) {
                $found[] = basename($file).' '.$global;
            }
        }
    }

    expect($found)->toBe([]);
});

test('PN5 checks the four built-in prompts, and its lists catch a leak', function () {
    $leak = '<?php $a = file_get_contents("/etc/hostname"); $b = new \SplFileObject("x"); $c = new PDO("sqlite::memory:");';

    expect(promptsHoldNoAppDataFiles())->toHaveCount(4)
        ->and(promptsHoldNoAppDataNamespaces())->toHaveCount(4)
        ->and(SourceScanner::bannedCallsInCode($leak, PROMPTS_HOLD_NO_APP_DATA_FUNCTIONS))->toBe([['file_get_contents', 1]])
        ->and(SourceScanner::bannedClassesInCode($leak, PROMPTS_HOLD_NO_APP_DATA_CLASSES))->toBe([['SplFileObject', 1], ['PDO', 1]]);
});
