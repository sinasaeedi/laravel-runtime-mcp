<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tools\Pipeline;

use Closure;
use SinaSaeedi\RuntimeMcp\Audit\Reason;
use SinaSaeedi\RuntimeMcp\Security\Auth\CurrentPrincipal;
use SinaSaeedi\RuntimeMcp\Tools\ToolCall;
use SinaSaeedi\RuntimeMcp\Tools\ToolOutcome;

/**
 * Stage 1 (spec 4.4): the principal of this request, from the scoped binding (01-06). No principal
 * (a call that did not pass the auth middleware) is a denial, not an error.
 */
final class ResolvePrincipalStage implements Stage
{
    public const NOTE = 'No authenticated principal.';

    public function __construct(private readonly CurrentPrincipal $principals) {}

    public function handle(ToolCall $call, Closure $next): ToolOutcome
    {
        $principal = $this->principals->get();

        if ($principal === null) {
            return ToolOutcome::denied(Reason::NoPrincipal, self::NOTE);
        }

        $call->setPrincipal($principal);

        return $next($call);
    }
}
