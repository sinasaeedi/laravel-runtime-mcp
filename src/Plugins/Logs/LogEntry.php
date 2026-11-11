<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins\Logs;

use Carbon\CarbonImmutable;

/** 01-25 Part C: one parsed entry, before redaction. Log lines are untrusted data (spec 5.12). */
final readonly class LogEntry
{
    /**
     * @param  string  $level  lower case, one of Monolog's eight names
     * @param  string  $env  Laravel's %channel% (the environment, or the channel's `name`)
     * @param  string|array<array-key, mixed>|null  $context  line format: the text after the message; JSON: the
     *                                                        record's `context` and `extra`
     */
    public function __construct(
        public CarbonImmutable $time,
        public string $level,
        public string $env,
        public string $message,
        public string|array|null $context,
    ) {}
}
