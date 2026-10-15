<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Support;

/**
 * Stands in for the http, https and ftp stream wrappers while a test runs (see OutboundGuard).
 */
final class BlockingStreamWrapper
{
    /** @var resource|null Set by PHP for every stream wrapper. */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        throw new OutboundCallAttempted($path);
    }

    /** @return array<int|string, int>|false */
    public function url_stat(string $path, int $flags): array|false
    {
        throw new OutboundCallAttempted($path);
    }
}
