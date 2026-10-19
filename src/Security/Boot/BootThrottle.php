<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Boot;

use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

/**
 * At most one report per 10 minutes per distinct set of issue ids, so a broken config under PHP-FPM
 * does not write a line (and fire an event) on every request.
 *
 * A package-owned file store, never the app's default store: Laravel's default is `database`, and a
 * query at boot breaks spec 5.11 (Sina, 2026-10-19). Each server throttles on its own disk.
 */
final class BootThrottle
{
    public const SECONDS = 600;

    /** Under storage_path(). */
    public const DIRECTORY = 'framework/cache/runtime-mcp';

    public function __construct(
        private readonly CacheManager $cache,
        private readonly Application $app,
    ) {}

    /**
     * True when this kind of report with these ids was not sent in the last 10 minutes. A store that
     * throws (a read-only disk) also gives true: visibility wins over silence.
     *
     * @param  list<string>  $ids
     */
    public function shouldReport(string $kind, array $ids): bool
    {
        try {
            return $this->cache
                ->build(['driver' => 'file', 'path' => $this->app->storagePath(self::DIRECTORY)])
                ->add('boot:'.$kind.':'.sha1(implode(',', $ids)), true, self::SECONDS);
        } catch (Throwable) {
            return true;
        }
    }
}
