<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Support;

/**
 * Replaces the http, https, ftp and ftps stream wrappers, so file_get_contents('https://…'), fopen(),
 * file_exists() and copy() throw OutboundCallAttempted. Never touches the file wrapper.
 * Both methods are safe to call twice, and uninstall() is safe without install().
 */
final class OutboundGuard
{
    private const SCHEMES = ['http', 'https', 'ftp', 'ftps'];

    public static function install(): void
    {
        foreach (self::SCHEMES as $scheme) {
            if (in_array($scheme, stream_get_wrappers(), true)) {
                stream_wrapper_unregister($scheme);
            }

            stream_wrapper_register($scheme, BlockingStreamWrapper::class);
        }
    }

    public static function uninstall(): void
    {
        foreach (self::SCHEMES as $scheme) {
            // Unregister first: stream_wrapper_restore() on an unchanged wrapper raises a notice,
            // and Laravel turns notices into exceptions.
            if (in_array($scheme, stream_get_wrappers(), true)) {
                stream_wrapper_unregister($scheme);
            }

            stream_wrapper_restore($scheme);
        }
    }
}
