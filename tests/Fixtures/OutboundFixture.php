<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures;

/**
 * Code that tries to reach the network through stream wrappers. OutboundGuardTest proves that
 * each method throws OutboundCallAttempted.
 */
final class OutboundFixture
{
    public function readHttps(): string|false
    {
        return file_get_contents('https://example.com');
    }

    /** @return resource|false */
    public function openHttp(): mixed
    {
        return fopen('http://example.com', 'r');
    }

    public function copyFtp(): bool
    {
        return copy('ftp://example.com/x', sys_get_temp_dir().'/x');
    }
}
