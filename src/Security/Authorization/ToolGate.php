<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Authorization;

use Illuminate\Contracts\Auth\Access\Gate;
use SinaSaeedi\RuntimeMcp\Audit\Reason;
use SinaSaeedi\RuntimeMcp\Principal;
use SinaSaeedi\RuntimeMcp\Tools\Contracts\RuntimeTool;
use Throwable;

/**
 * Spec 5.5: default deny. A tool is allowed only when the app defines the Gate
 * runtime-mcp.<tool_name> and it allows the principal's subject (the linked User, or the Principal).
 *
 * The one decision for both call sites: McpToolAdapter::shouldRegister() hides the tool, and
 * CheckGateStage (pipeline stage 2) enforces it. Nothing is cached: a Gate can depend on the time,
 * a role or a feature flag.
 */
final class ToolGate
{
    public function __construct(
        private readonly Gate $gate,
        private readonly GateWarning $warning,
    ) {}

    public function decide(?Principal $principal, RuntimeTool $tool): GateDecision
    {
        if ($principal === null) {
            return GateDecision::deny(Reason::NoPrincipal);
        }

        $ability = $tool->ability();

        // Before the Gate is asked: an app's Gate::before() (a "super admin" hook) would otherwise
        // allow an ability that the team never defined (rule 12).
        if (! $this->gate->has($ability)) {
            return GateDecision::deny(Reason::NoGate);
        }

        $subject = $principal->subject();

        try {
            // Always forUser(): without it the Gate asks the web guard, which this package never sets.
            $response = $this->gate->forUser($subject)->inspect($ability);
        } catch (Throwable $e) {
            $this->warning->write("the Gate {$ability}", $e, $subject);

            return GateDecision::deny(Reason::GateError);
        }

        return $response->allowed()
            ? GateDecision::allow()
            : GateDecision::deny(Reason::GateDenied, $response->message());
    }
}
