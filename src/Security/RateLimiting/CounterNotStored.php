<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\RateLimiting;

use RuntimeException;

/**
 * 01-17: the limiter's cache store lost a write without an error (the null store, a Memcached server
 * that is down), so RateLimiter::hit() counted 0. RateLimitStage denies the call and AuditLogger writes
 * the short entry, as for a store that throws (fail closed, principle 1.4.3).
 */
final class CounterNotStored extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The rate limiter cache did not store the counter (the null store, or a cache server that is down).');
    }
}
