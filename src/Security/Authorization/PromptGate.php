<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Authorization;

use Illuminate\Contracts\Debug\ExceptionHandler;
use SinaSaeedi\RuntimeMcp\Audit\Reason;
use SinaSaeedi\RuntimeMcp\Principal;
use SinaSaeedi\RuntimeMcp\Prompts\Contracts\RuntimePrompt;
use SinaSaeedi\RuntimeMcp\Tools\Contracts\RuntimeTool;
use Throwable;

/**
 * Spec 5.5, 5.14: a prompt has no ability of its own. It is allowed only when a principal is set and
 * ToolGate allows every tool in its list; a tool that is not registered (its plugin is off) counts as
 * denied, and an empty list is denied (default deny). The one decision for both call sites:
 * McpPromptAdapter::shouldRegister() hides the prompt, and Methods\GetPrompt enforces it.
 *
 * Lives here, beside ToolGate, and not in src/Prompts/: a prompt class may use only Prompts\* (rule 18).
 */
final class PromptGate
{
    public function __construct(
        private readonly ToolGate $gate,
        private readonly ExceptionHandler $exceptions,
    ) {}

    /** @param  array<string, RuntimeTool>  $tools  the registered tools, by name */
    public function decide(?Principal $principal, RuntimePrompt $prompt, array $tools): PromptDecision
    {
        if ($principal === null) {
            return PromptDecision::deny(Reason::NoPrincipal);
        }

        try {
            $names = $prompt->tools();
        } catch (Throwable $e) {
            // A custom prompt's own code failed. Hidden and denied, never raised (spec 3.2: debug mode rethrows).
            $this->report($e);

            return PromptDecision::deny(Reason::GateError);
        }

        if ($names === []) {
            return PromptDecision::deny(Reason::PromptToolDenied);
        }

        foreach ($names as $name) {
            $tool = $tools[$name] ?? null;

            if ($tool === null || ! $this->gate->decide($principal, $tool)->allowed) {
                return PromptDecision::deny(Reason::PromptToolDenied, $name);
            }
        }

        return PromptDecision::allow();
    }

    private function report(Throwable $e): void
    {
        try {
            $this->exceptions->report($e);
        } catch (Throwable) {
            // Nothing left to tell. The prompt stays denied.
        }
    }
}
