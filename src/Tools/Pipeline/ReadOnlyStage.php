<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tools\Pipeline;

use Closure;
use SinaSaeedi\RuntimeMcp\Security\ReadOnly\ReadOnlyTransaction;
use SinaSaeedi\RuntimeMcp\Security\ReadOnly\ReadOnlyTransactionEnded;
use SinaSaeedi\RuntimeMcp\Security\ReadOnly\ReadOnlyTransactionUnavailable;
use SinaSaeedi\RuntimeMcp\Tools\ToolCall;
use SinaSaeedi\RuntimeMcp\Tools\ToolOutcome;

/**
 * Stages 4 and 6 (spec 4.4, 5.9), the innermost stage: open the read-only transaction, run the tool,
 * roll back and restore. Fail closed: the tool never runs outside the transaction.
 *
 * No tier check: Tier has only read and read_sensitive in v1.0.0. v1.13.0's write tier sets its own rule.
 * The write exception is not caught here: RunTool turns it into the note (WriteAttemptExplainer).
 */
final class ReadOnlyStage implements Stage
{
    public const NOT_OPENED = 'The read-only transaction could not be opened: %s The tool did not run.';

    public const ENDED = 'App code ended the read-only transaction on %s (a reconnect or a direct PDO commit). A write after that point may have been saved.';

    public function __construct(private readonly ReadOnlyTransaction $transaction) {}

    public function handle(ToolCall $call, Closure $next): ToolOutcome
    {
        try {
            $this->transaction->open();                     // stage 4; a partial open is closed inside
        } catch (ReadOnlyTransactionUnavailable $e) {
            return ToolOutcome::failed($e, sprintf(self::NOT_OPENED, $e->getMessage()));
        }

        $ended = [];

        try {
            $outcome = $next($call);                        // stage 5
        } finally {
            $ended = $this->transaction->close();          // stage 6; never throws
        }

        if ($ended === []) {
            return $outcome;
        }

        return ToolOutcome::failed(
            new ReadOnlyTransactionEnded($ended[0]),
            sprintf(self::ENDED, ReadOnlyTransactionUnavailable::shown($ended[0])),
        );
    }
}
