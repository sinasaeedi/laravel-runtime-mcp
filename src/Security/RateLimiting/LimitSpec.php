<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\RateLimiting;

/**
 * Spec 4.5, 5.8: one count limit, written as "attempts,minutes" (the format of Laravel's throttle
 * middleware): '120,1' is 120 calls per minute.
 */
final readonly class LimitSpec
{
    /** Both parts are whole numbers of 1 or more, with no spaces. The digit limits keep seconds an int. */
    public const PATTERN = '/^[1-9][0-9]{0,8},[1-9][0-9]{0,5}$/D';

    private function __construct(
        public int $maxAttempts,
        public int $decayMinutes,
    ) {}

    /** Null for anything that is not "attempts,minutes": the caller falls back to a default (01-17 Part A). */
    public static function parse(mixed $value): ?self
    {
        if (! is_string($value) || preg_match(self::PATTERN, $value) !== 1) {
            return null;
        }

        [$attempts, $minutes] = explode(',', $value);

        return new self((int) $attempts, (int) $minutes);
    }

    /** RateLimiter::hit() takes seconds, not minutes. */
    public function decaySeconds(): int
    {
        return $this->decayMinutes * 60;
    }

    /** "60 calls per minute", "1 call per 5 minutes". */
    public function describe(): string
    {
        $calls = $this->maxAttempts === 1 ? '1 call' : "{$this->maxAttempts} calls";

        return $this->decayMinutes === 1 ? "{$calls} per minute" : "{$calls} per {$this->decayMinutes} minutes";
    }
}
