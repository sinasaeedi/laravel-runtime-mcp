<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Authorization;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One warning in the app's default log when a Gate, a Policy, a Gate::before() or Gate::after()
 * callback, or a GateEvaluated listener throws (spec 1.4.3: the check then denies). All of them run
 * inside Gate::inspect(), so the warning cannot tell which one threw and names them all. The common
 * cause is a callback typed User and an unlinked key, whose subject is a Principal. The warning names
 * the check, the exception class and the subject class, and how to fix it. Never the exception
 * message, the trace or the arguments.
 */
final class GateWarning
{
    public const MESSAGE = 'runtime-mcp: %s (or a Gate::before() or Gate::after() callback, or a GateEvaluated listener) threw %s for a %s subject. Type the callback as Principal|User.';

    public function __construct(private readonly LoggerInterface $log) {}

    /** @param  string  $check  "the Gate runtime-mcp.db_query" or "the view Policy of App\Models\Order" */
    public function write(string $check, Throwable $error, object $subject): void
    {
        try {
            $this->log->warning(sprintf(self::MESSAGE, $check, $error::class, class_basename($subject)));
        } catch (Throwable) {
            // The denial stands without the warning: a broken log never opens a tool.
        }
    }
}
