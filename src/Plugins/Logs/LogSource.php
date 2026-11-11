<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins\Logs;

/** 01-25 Part A: one `single` or `daily` channel that writes a file this tool can read. */
final readonly class LogSource
{
    public const LINE = 'line';

    public const JSON = 'json';

    /** When max_files and days are missing, 0 (Monolog: unlimited) or not a whole number. */
    public const DEFAULT_DAILY_FILES = 14;

    /**
     * @param  string  $channel  the single or daily channel's name in logging.channels
     * @param  string  $path  its `path` (for daily: the name Monolog dates)
     * @param  self::LINE|self::JSON  $format
     * @param  int  $maxFiles  daily only: the most files to read
     */
    public function __construct(
        public string $channel,
        public string $path,
        public string $format,
        public bool $daily,
        public int $maxFiles = 1,
    ) {}
}
