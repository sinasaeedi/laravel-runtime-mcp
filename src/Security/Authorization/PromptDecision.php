<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Authorization;

use SinaSaeedi\RuntimeMcp\Audit\Reason;

/**
 * What PromptGate decided for one principal (spec 5.5). A denial always has a reason, and names the
 * first tool that was denied or not registered when there is one (the audit's details.tool).
 */
final readonly class PromptDecision
{
    public bool $allowed;

    private function __construct(
        public ?Reason $reason,
        public ?string $tool,
    ) {
        $this->allowed = $reason === null;
    }

    public static function allow(): self
    {
        return new self(null, null);
    }

    public static function deny(Reason $reason, ?string $tool = null): self
    {
        return new self($reason, $tool);
    }
}
