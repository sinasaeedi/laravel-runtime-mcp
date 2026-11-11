<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins\Logs;

/** 01-25 Part A: what one channel of runtime-mcp.logs.channels resolves to, from the config only. */
final readonly class ResolvedChannel
{
    /**
     * @param  list<LogSource>  $sources  the file channels, in config order
     * @param  list<string>  $skipped  one sentence for each part this tool cannot read
     */
    public function __construct(
        public array $sources,
        public array $skipped,
    ) {}
}
