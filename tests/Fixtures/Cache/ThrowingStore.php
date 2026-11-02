<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Cache;

use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use RuntimeException;

/**
 * A cache store that is down: every call throws (01-17, a Redis outage). Install it with
 * ThrowingStore::limiter(): Testbench builds the RateLimiter at boot, so a config change comes too late.
 */
final class ThrowingStore implements Store
{
    public const MESSAGE = 'Connection refused [tcp://cache:6379]';

    public static function limiter(): void
    {
        app()->instance(RateLimiter::class, new RateLimiter(new Repository(new self)));
    }

    public function get($key): mixed
    {
        throw new RuntimeException(self::MESSAGE);
    }

    /**
     * @param  array<array-key, mixed>  $keys
     * @return array<array-key, mixed>
     */
    public function many(array $keys): array
    {
        throw new RuntimeException(self::MESSAGE);
    }

    public function put($key, $value, $seconds): bool
    {
        throw new RuntimeException(self::MESSAGE);
    }

    /** @param  array<array-key, mixed>  $values */
    public function putMany(array $values, $seconds): bool
    {
        throw new RuntimeException(self::MESSAGE);
    }

    public function increment($key, $value = 1): int|bool
    {
        throw new RuntimeException(self::MESSAGE);
    }

    public function decrement($key, $value = 1): int|bool
    {
        throw new RuntimeException(self::MESSAGE);
    }

    public function forever($key, $value): bool
    {
        throw new RuntimeException(self::MESSAGE);
    }

    /** Laravel 13 adds touch() to the contract; 12.69 does not have it, and an extra method is allowed. */
    public function touch($key, $seconds): bool
    {
        throw new RuntimeException(self::MESSAGE);
    }

    public function forget($key): bool
    {
        throw new RuntimeException(self::MESSAGE);
    }

    public function flush(): bool
    {
        throw new RuntimeException(self::MESSAGE);
    }

    public function getPrefix(): string
    {
        return '';
    }
}
