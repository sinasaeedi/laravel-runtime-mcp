<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tools;

/**
 * Spec 5.15: how far a long tool has got. A tool calls `$input->progress()->report(...)`; the package sends a
 * `notifications/progress` only when the request carried `_meta.progressToken` (FiberProgress), else nothing happens
 * (NullProgress). Every call of report() is safe: a report that cannot or must not go out is dropped.
 */
interface Progress
{
    /**
     * @param  int  $done  must grow on each call; a report that does not grow is dropped
     * @param  int|null  $total  the whole amount of work, when the tool knows it
     * @param  string|null  $message  counts and names from the config only (spec 4.3, 5.15), never app data
     */
    public function report(int $done, ?int $total = null, ?string $message = null): void;
}
