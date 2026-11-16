<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tools;

use Fiber;
use SinaSaeedi\RuntimeMcp\Security\Redaction\Redactor;

/**
 * Spec 5.15: the progress of a call whose client sent a progressToken. McpToolAdapter runs the guard pipeline inside
 * one Fiber; an accepted report suspends that fiber with a ProgressReport, the adapter sends it as a notification,
 * then resumes the fiber. Every stage of the pipeline still runs, in the same order, in the same process.
 *
 * One object per call (rule 14). A report is dropped, never an error, when:
 * - it does not run inside the call's own fiber: no fiber at all, or a fiber that app code started inside the tool
 *   (suspending that one would hand the report to the app's code);
 * - MAX_REPORTS were already sent for this call;
 * - $done is not greater than the last sent value (the MCP spec: progress must grow);
 * - less than MIN_INTERVAL_NS passed since the last sent report (the first report always goes).
 *
 * The message is a new output channel, so it passes the redaction engine (spec 5.6) and is cut to MAX_MESSAGE
 * characters, in that order: a cut first could split a secret so that no pattern matches it.
 */
final class FiberProgress implements Progress
{
    /** Spec 5.15: at most 20 notifications for one call. */
    public const MAX_REPORTS = 20;

    /** Spec 5.15: at most one notification per second. */
    public const MIN_INTERVAL_NS = 1_000_000_000;

    /** Spec 5.15: a message is cut to 200 characters. */
    public const MAX_MESSAGE = 200;

    private int $sent = 0;

    private ?int $lastDone = null;

    private ?int $lastSentAt = null;

    /** @param  Fiber<mixed, mixed, mixed, mixed>  $fiber  the call's fiber, made before this object */
    public function __construct(
        private readonly Fiber $fiber,
        private readonly Redactor $redactor,
        private readonly ProgressClock $clock,
    ) {}

    public function report(int $done, ?int $total = null, ?string $message = null): void
    {
        if (Fiber::getCurrent() !== $this->fiber) {
            return;
        }

        if ($this->sent >= self::MAX_REPORTS) {
            return;
        }

        if ($this->lastDone !== null && $done <= $this->lastDone) {
            return;
        }

        $now = $this->clock->now();

        if ($this->lastSentAt !== null && $now - $this->lastSentAt < self::MIN_INTERVAL_NS) {
            return;
        }

        $this->sent++;
        $this->lastDone = $done;
        $this->lastSentAt = $now;

        $shown = $message === null ? null : mb_substr($this->redactor->redactString($message), 0, self::MAX_MESSAGE);

        Fiber::suspend(new ProgressReport($done, $total, $shown));
    }
}
