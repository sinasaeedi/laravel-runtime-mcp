<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tools\Pipeline;

use Closure;
use SinaSaeedi\RuntimeMcp\Tools\ToolCall;
use SinaSaeedi\RuntimeMcp\Tools\ToolOutcome;

/**
 * One stage of the guard pipeline (spec 4.4). A stage works on the way in (before $next), on the
 * way out (after $next), or both. It is built from the container for each call (rule 14).
 */
interface Stage
{
    /** @param  Closure(ToolCall): ToolOutcome  $next */
    public function handle(ToolCall $call, Closure $next): ToolOutcome;
}
