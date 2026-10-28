<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tools\Pipeline;

use Closure;
use SinaSaeedi\RuntimeMcp\Audit\Reason;
use SinaSaeedi\RuntimeMcp\Security\Authorization\GateDecision;
use SinaSaeedi\RuntimeMcp\Security\Authorization\ToolGate;
use SinaSaeedi\RuntimeMcp\Security\Redaction\Redactor;
use SinaSaeedi\RuntimeMcp\Tools\ToolCall;
use SinaSaeedi\RuntimeMcp\Tools\ToolOutcome;

/**
 * Stage 2 (spec 4.4, 5.5): the enforced Gate check, on every call. shouldRegister() only hides a
 * tool, and laravel/mcp skips it on some paths (a direct ToolInvoker call, our own tools/call).
 * A denial passes out through redaction and the audit like any other outcome.
 */
final class CheckGateStage implements Stage
{
    public const NO_GATE = 'Access denied: the app defines no Gate `%s`, so this tool is off (default deny).';

    public const GATE_DENIED = 'Access denied: the Gate `%s` does not allow this principal.';

    public const GATE_ERROR = 'Access denied: the Gate `%s` failed. The server log names the cause.';

    /** The longest Gate message kept in the note, in characters. */
    public const MAX_MESSAGE = 200;

    public function __construct(
        private readonly ToolGate $gate,
        private readonly Redactor $redactor,
    ) {}

    public function handle(ToolCall $call, Closure $next): ToolOutcome
    {
        $decision = $this->gate->decide($call->principal(), $call->tool);

        if ($decision->reason === null) {
            return $next($call);
        }

        return ToolOutcome::denied($decision->reason, $this->note($call->tool->ability(), $decision));
    }

    /**
     * The Gate's message is redacted whole, then cut. The other order would hand RedactStage a secret
     * cut in half, which no pattern matches (a password with no "@" after it, a token too short).
     */
    private function note(string $ability, GateDecision $decision): string
    {
        $note = match ($decision->reason) {
            Reason::NoGate => self::NO_GATE,
            Reason::GateError => self::GATE_ERROR,
            default => self::GATE_DENIED,
        };
        $note = sprintf($note, $ability);

        if ($decision->message !== null && $decision->message !== '') {
            $note .= ' '.mb_substr($this->redactor->redactString($decision->message), 0, self::MAX_MESSAGE);
        }

        return $note;
    }
}
