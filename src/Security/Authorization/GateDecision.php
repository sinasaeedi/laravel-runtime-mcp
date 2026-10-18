<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Authorization;

use SinaSaeedi\RuntimeMcp\Audit\Reason;

/**
 * What ToolGate or ModelPolicy decided for one principal (spec 5.5). A denial always has a reason;
 * an allowed decision never has one.
 */
final readonly class GateDecision
{
    public bool $allowed;

    /** @param  string|null  $message  the Gate's or the Policy's own denial message, if it gave one */
    private function __construct(
        public ?Reason $reason,
        public ?string $message,
    ) {
        $this->allowed = $reason === null;
    }

    public static function allow(): self
    {
        return new self(null, null);
    }

    public static function deny(Reason $reason, ?string $message = null): self
    {
        return new self($reason, $message);
    }
}
