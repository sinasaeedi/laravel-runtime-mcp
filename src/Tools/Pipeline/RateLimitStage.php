<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tools\Pipeline;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use SinaSaeedi\RuntimeMcp\Audit\Reason;
use SinaSaeedi\RuntimeMcp\Security\Environment;
use SinaSaeedi\RuntimeMcp\Security\RateLimiting\CounterNotStored;
use SinaSaeedi\RuntimeMcp\Security\RateLimiting\LimitSettings;
use SinaSaeedi\RuntimeMcp\Security\RateLimiting\LimitSpec;
use SinaSaeedi\RuntimeMcp\Tools\ToolCall;
use SinaSaeedi\RuntimeMcp\Tools\ToolOutcome;
use Throwable;

/**
 * Stage 3 (spec 4.4, 5.8): the count limits, per principal and per tool, in Laravel's RateLimiter.
 * After the Gate (stage 2), so a denied call never reaches it; before the read-only transaction
 * (stage 4), so the limiter's cache writes never run inside it (01-16).
 *
 * The counters live in the cache, never in this object (rule 14, spec 5.13). The keys hold the
 * environment, so one cache shared by staging and production never mixes counters.
 */
final class RateLimitStage implements Stage
{
    /** %1$s is LimitSpec::describe(), %2$s "this key" or the tool name. */
    public const LIMITED = 'Rate limit reached: %1$s for %2$s. Try again in %3$d seconds.';

    public const THIS_KEY = 'this key';

    public const UNAVAILABLE = 'Rate limits could not be checked (cache unavailable), so the call was not run.';

    public function __construct(
        private readonly LimitSettings $limits,
        private readonly Environment $environment,
        private readonly Container $container,
    ) {}

    public static function principalKey(string $environment, string $principal): string
    {
        return "runtime-mcp:{$environment}:principal:{$principal}";
    }

    public static function toolKey(string $environment, string $tool, string $principal): string
    {
        return "runtime-mcp:{$environment}:tool:{$tool}:{$principal}";
    }

    public function handle(ToolCall $call, Closure $next): ToolOutcome
    {
        $principal = $call->principal();

        if ($principal === null) {
            // Stage 1 denies first; this keeps the stage safe if it is ever moved.
            return ToolOutcome::denied(Reason::NoPrincipal, ResolvePrincipalStage::NOTE);
        }

        $environment = $this->environment->name();
        $tool = $call->tool->name();

        /** @var list<array{string, LimitSpec, string}> $checks key, limit, who */
        $checks = [[self::principalKey($environment, $principal->name), $this->limits->perPrincipal(), self::THIS_KEY]];
        $toolLimit = $this->limits->forTool($tool);

        if ($toolLimit !== null) {
            $checks[] = [self::toolKey($environment, $tool, $principal->name), $toolLimit, $tool];
        }

        try {
            $limiter = $this->container->make(RateLimiter::class);

            // Check every limit before any hit, so a denied call uses up no budget.
            foreach ($checks as [$key, $limit, $who]) {
                if ($limiter->tooManyAttempts($key, $limit->maxAttempts)) {
                    return ToolOutcome::denied(Reason::RateLimited, sprintf(self::LIMITED, $limit->describe(), $who, max(1, $limiter->availableIn($key))));
                }
            }

            foreach ($checks as [$key, $limit]) {
                // A store that loses the write without an error (null, a Memcached outage) counts 0: not a pass either.
                if ($limiter->hit($key, $limit->decaySeconds()) < 1) {
                    throw new CounterNotStored;
                }
            }
        } catch (Throwable $e) {
            // Fail closed (principle 1.4.3): a limit that cannot be checked is not a pass.
            $this->report($e);

            return ToolOutcome::denied(Reason::RateLimitUnavailable, self::UNAVAILABLE);
        }

        // Outside the try: a tool's own exception is not a cache error.
        return $next($call);
    }

    /** The handler may fail too. The denial still goes out. */
    private function report(Throwable $e): void
    {
        try {
            $this->container->make(ExceptionHandler::class)->report($e);
        } catch (Throwable) {
            // Nothing left to tell.
        }
    }
}
