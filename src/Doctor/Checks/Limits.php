<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Doctor\Checks;

use Illuminate\Contracts\Config\Repository;
use SinaSaeedi\RuntimeMcp\Doctor\Check;
use SinaSaeedi\RuntimeMcp\Doctor\CheckResult;
use SinaSaeedi\RuntimeMcp\Security\Environment;
use SinaSaeedi\RuntimeMcp\Security\RateLimiting\LimitSettings;
use SinaSaeedi\RuntimeMcp\Tools\OutputCap;

/**
 * Spec 4.5, 5.8, 01-17 Part A: rate_limits and output.max_bytes. A bad value is replaced by the spec
 * default at run time, so it fails here: the operator thinks another limit is in force. Never prints a
 * value (Sina, 2026-10-20: print a config value only when it has the shape of a name).
 *
 * Also the cache store that holds the counters (Sina, 2026-11-02, 01-17 triage), from the config only:
 * doctor never calls the store, so it never writes to the cache.
 */
final class Limits implements Check
{
    public const LIMIT_FIX = "Write each limit as 'attempts,minutes', for example '120,1'.";

    public const BAD_MAX_BYTES = 'output.max_bytes is not a whole number of at least '.OutputCap::MIN_MAX_BYTES.', so '.OutputCap::DEFAULT_MAX_BYTES.' is used.';

    public const MAX_BYTES_FIX = 'Set it to the cap in bytes, for example 100000.';

    /** %s is the store, as store() shows it. */
    public const NULL_STORE = 'The rate limits count in %s, which has the null driver and keeps nothing, so every tool call is denied (rate_limit_unavailable).';

    public const UNDEFINED_STORE = 'The rate limits count in %s, which is not in cache.stores, so every tool call is denied (rate_limit_unavailable).';

    public const ARRAY_STORE = 'The rate limits count in %s, which has the array driver: each PHP process counts on its own, so under PHP-FPM or Octane the limits do not hold.';

    public const STORE_FIX = 'Set cache.limiter (or cache.default) to a shared store, for example redis or database.';

    public function __construct(
        private readonly LimitSettings $limits,
        private readonly OutputCap $cap,
        private readonly Repository $config,
        private readonly Environment $environment,
    ) {}

    public function title(): string
    {
        return 'Limits';
    }

    public function run(): array
    {
        $results = array_map(
            fn (string $problem): CheckResult => CheckResult::fail($problem, self::LIMIT_FIX),
            $this->limits->problems(),
        );

        if (! $this->cap->maxBytesIsValid()) {
            $results[] = CheckResult::fail(self::BAD_MAX_BYTES, self::MAX_BYTES_FIX);
        }

        if ($results === []) {
            $results[] = CheckResult::pass($this->summary());
        }

        $store = $this->store();

        return $store === null ? $results : [...$results, $store];
    }

    /**
     * The limiter's store as CacheServiceProvider picks it: cache.limiter, else cache.default, else "null".
     * The null driver and a missing store deny every call (RateLimitStage fails closed); the array driver
     * counts per process, which is normal only in a relaxed environment.
     */
    private function store(): ?CheckResult
    {
        $name = $this->config->get('cache.limiter') ?? $this->config->get('cache.default') ?? 'null';
        // As CacheManager::getConfig(): the name "null" needs no entry in cache.stores.
        $driver = $name === 'null' ? 'null' : (is_string($name) ? $this->config->get("cache.stores.{$name}.driver") : null);
        $shown = is_string($name) && preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $name) === 1 ? "the cache store `{$name}`" : 'the limiter cache store';

        return match (true) {
            $driver === 'null' => CheckResult::fail(sprintf(self::NULL_STORE, $shown), self::STORE_FIX),
            ! is_string($driver) => CheckResult::fail(sprintf(self::UNDEFINED_STORE, $shown), self::STORE_FIX),
            $driver === 'array' && $this->environment->isStrict() => CheckResult::warn(sprintf(self::ARRAY_STORE, $shown), self::STORE_FIX),
            default => null,
        };
    }

    /** "120 calls per minute per key; db_query 60 calls per minute; results at most 100000 bytes." */
    private function summary(): string
    {
        $parts = [$this->limits->perPrincipal()->describe().' per key'];
        $perTool = $this->limits->perToolInUse();

        foreach ($perTool as $tool => $limit) {
            $parts[] = "{$tool} {$limit->describe()}";
        }

        if ($perTool === []) {
            $parts[] = 'no per-tool limit';
        }

        $parts[] = 'results at most '.$this->cap->limit().' bytes';

        return ucfirst(implode('; ', $parts)).'.';
    }
}
