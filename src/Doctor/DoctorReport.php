<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Doctor;

use SinaSaeedi\RuntimeMcp\Doctor\Checks\BootRules;
use SinaSaeedi\RuntimeMcp\Doctor\Checks\Route;

/**
 * The lines of one doctor run, and the rules that turn them into counts, a footer and an exit code
 * (spec 4.6, 7.3).
 */
final readonly class DoctorReport
{
    /** A Fail of these checks keeps the endpoint off; every other Fail makes calls fail while it is on. */
    public const ENDPOINT_CHECKS = [BootRules::class, Route::class];

    /** @param  list<DoctorLine>  $lines  in report order */
    public function __construct(
        public array $lines,
        public bool $relaxed,
    ) {}

    /** Spec 4.6, third row: a failed privilege check is only a warning in a relaxed environment. */
    public function status(DoctorLine $line): CheckStatus
    {
        if ($this->relaxed && $line->result->privilege && $line->result->status === CheckStatus::Fail) {
            return CheckStatus::Warn;
        }

        return $line->result->status;
    }

    public function count(CheckStatus $status): int
    {
        return count(array_filter($this->lines, fn (DoctorLine $line): bool => $this->status($line) === $status));
    }

    public function blocksEndpoint(): bool
    {
        foreach ($this->lines as $line) {
            if ($this->status($line) === CheckStatus::Fail && in_array($line->source, self::ENDPOINT_CHECKS, true)) {
                return true;
            }
        }

        return false;
    }

    /** Any Fail exits 1, in every environment (spec 7.3; 01-12 decision). */
    public function exitCode(): int
    {
        return $this->count(CheckStatus::Fail) > 0 ? 1 : 0;
    }

    /** The footer line, for example "1 failure, 2 warnings. Fix them before the release goes live." */
    public function summary(): string
    {
        $failures = $this->count(CheckStatus::Fail);
        $warnings = $this->count(CheckStatus::Warn);

        if ($failures === 0 && $warnings === 0) {
            return 'Every check passes.';
        }

        $parts = [];

        if ($failures > 0) {
            $parts[] = $failures.($failures === 1 ? ' failure' : ' failures');
        }

        if ($warnings > 0) {
            $parts[] = $warnings.($warnings === 1 ? ' warning' : ' warnings');
        }

        $summary = implode(', ', $parts).'.';

        if ($this->blocksEndpoint()) {
            return $summary.' The endpoint stays off until every boot rule and route failure is fixed.';
        }

        return $failures > 0 ? $summary.' Fix them before the release goes live.' : $summary;
    }
}
