<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Doctor;

/**
 * One line of the doctor report. The message says what is true now; the fix says what to do. Neither
 * may hold a secret: no key, no hash, no .env value, no password (spec 7.3, 01-10).
 */
final readonly class CheckResult
{
    public function __construct(
        public CheckStatus $status,
        public string $message,
        public ?string $fix = null,
        // True for the spec 5.1 privilege checks (01-13): a Fail is only a warning in a relaxed environment (spec 4.6).
        public bool $privilege = false,
    ) {}

    public static function pass(string $message): self
    {
        return new self(CheckStatus::Pass, $message);
    }

    public static function warn(string $message, ?string $fix = null): self
    {
        return new self(CheckStatus::Warn, $message, $fix);
    }

    public static function fail(string $message, ?string $fix = null): self
    {
        return new self(CheckStatus::Fail, $message, $fix);
    }
}
