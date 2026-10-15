<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The files that the static checks in tests/Arch read.
 */
final class SourceFiles
{
    /** @return list<string> Absolute paths of every `.php` file under `src/`, sorted. */
    public static function paths(): array
    {
        $root = dirname(__DIR__, 2).'/src';
        $paths = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                $paths[] = $file->getPathname();
            }
        }

        sort($paths);

        return $paths;
    }

    /** @return list<string> The PSR-4 class name of each path (`SinaSaeedi\RuntimeMcp\…`). */
    public static function classNames(): array
    {
        $root = dirname(__DIR__, 2).'/src/';

        return array_map(
            static fn (string $path): string => 'SinaSaeedi\\RuntimeMcp\\'.str_replace('/', '\\', substr($path, strlen($root), -4)),
            self::paths(),
        );
    }
}
