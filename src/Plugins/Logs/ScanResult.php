<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins\Logs;

/** 01-25 Part D: what one scan read and kept. */
final readonly class ScanResult
{
    /**
     * @param  list<array<string, mixed>>  $kept  what the keep closure returned, newest first
     * @param  list<string>  $files  the base names of the files read (fully, or down to `from`), oldest first
     * @param  bool  $cut  the budget ran out before every source ended
     * @param  list<string>  $skipped  the reason for each file that could not be read
     * @param  int  $bytes  the bytes read
     */
    public function __construct(
        public array $kept,
        public array $files,
        public bool $cut,
        public array $skipped,
        public int $bytes,
    ) {}
}
