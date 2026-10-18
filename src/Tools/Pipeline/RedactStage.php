<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tools\Pipeline;

use Closure;
use SinaSaeedi\RuntimeMcp\Security\Redaction\Redactor;
use SinaSaeedi\RuntimeMcp\Tools\ToolCall;
use SinaSaeedi\RuntimeMcp\Tools\ToolOutcome;

/**
 * Stage 7 (spec 4.4, 5.6): the redaction engine over the note and the data, on the way out. It
 * runs for denials and failures too: a failure note holds an exception message.
 *
 * The Redactor is a constructor dependency, so a bad extra_patterns entry throws when the pipeline
 * builds this stage. The audit stage turns that into a failed call (01-04 note).
 */
final class RedactStage implements Stage
{
    public function __construct(private readonly Redactor $redactor) {}

    public function handle(ToolCall $call, Closure $next): ToolOutcome
    {
        $outcome = $next($call);
        $result = $outcome->result;

        return $outcome->withResult($result->withContent(
            $this->redactor->redact($result->data),
            $result->note === null ? null : $this->redactor->redactString($result->note),
        ));
    }
}
