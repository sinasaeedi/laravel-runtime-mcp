<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tools;

use Illuminate\Contracts\Container\Container;
use Illuminate\Pipeline\Pipeline;
use LogicException;
use SinaSaeedi\RuntimeMcp\Tools\Pipeline\AuditStage;
use SinaSaeedi\RuntimeMcp\Tools\Pipeline\RedactStage;
use SinaSaeedi\RuntimeMcp\Tools\Pipeline\ResolvePrincipalStage;
use SinaSaeedi\RuntimeMcp\Tools\Pipeline\RunTool;

/**
 * Spec 4.4: the one pipeline around every tool call, over HTTP, over STDIO and inside execute_tools.
 *
 * The stages wrap each other like an onion. On the way in: principal (1), Gate (2), rate limit (3),
 * read-only transaction (4), run (5). On the way out: rollback (6), redact (7), cap (8), audit (9).
 * So a call denied at stage 1 or 2 still passes out through 7, 8 and 9: it is redacted, capped and
 * audited, as the spec asks.
 */
final class GuardPipeline
{
    /** Outermost first. The order is the security design; PipelineOrderTest pins it. */
    public const STAGES = [
        AuditStage::class,               // 9: outermost, sees every outcome, writes last
        // 01-17 inserts Pipeline\CapOutputStage here (8)
        RedactStage::class,              // 7
        ResolvePrincipalStage::class,    // 1
        // 01-09 inserts Pipeline\CheckGateStage here (2)
        // 01-17 inserts Pipeline\RateLimitStage here (3)
        // 01-16 inserts Pipeline\ReadOnlyStage here (4 on the way in, 6 on the way out)
    ];

    public function __construct(private readonly Container $container) {}

    public function run(ToolCall $call): ToolOutcome
    {
        $outcome = (new Pipeline($this->container))
            ->send($call)
            ->through(self::STAGES)
            ->via('handle')
            // 5: the destination. Built inside the pipeline, so a failure to build it is audited.
            ->then(fn (ToolCall $call): ToolOutcome => $this->container->make(RunTool::class)->handle($call));

        if (! $outcome instanceof ToolOutcome) {
            throw new LogicException('A pipeline stage did not return a ToolOutcome.');
        }

        return $outcome;
    }
}
