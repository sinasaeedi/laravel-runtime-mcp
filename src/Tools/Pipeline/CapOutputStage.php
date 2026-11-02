<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tools\Pipeline;

use Closure;
use SinaSaeedi\RuntimeMcp\Security\Environment;
use SinaSaeedi\RuntimeMcp\Tools\OutputCap;
use SinaSaeedi\RuntimeMcp\Tools\ToolCall;
use SinaSaeedi\RuntimeMcp\Tools\ToolOutcome;

/**
 * Stage 8 (spec 4.4, 5.8): the output cap, on the way out. It sits outside RedactStage, so it measures
 * (and cuts) the redacted result, and inside AuditStage, so the audit's output_bytes is the final size.
 * It runs for denials and failures too.
 *
 * Its constructor never throws: a stage outside stage 1 that fails when it is built would give an audit
 * entry with no principal (01-08 note).
 */
final class CapOutputStage implements Stage
{
    public function __construct(
        private readonly OutputCap $cap,
        private readonly Environment $environment,
    ) {}

    public function handle(ToolCall $call, Closure $next): ToolOutcome
    {
        $outcome = $next($call);

        return $outcome->withResult($this->cap->cap($outcome->result, $this->environment->name()));
    }
}
