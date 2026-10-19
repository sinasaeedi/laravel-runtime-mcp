<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Boot;

/**
 * One finding of a boot rule. The message names config keys and positions, never a secret or a
 * config value that could be one (it goes to the log, the event and the relaxed exception).
 */
final readonly class BootIssue
{
    public function __construct(
        public string $ruleId,
        public Severity $severity,
        public string $message,
    ) {}

    public static function error(string $ruleId, string $message): self
    {
        return new self($ruleId, Severity::Error, $message);
    }

    public static function warning(string $ruleId, string $message): self
    {
        return new self($ruleId, Severity::Warning, $message);
    }

    /** @return array{rule: string, severity: string, message: string} for the log context */
    public function toArray(): array
    {
        return ['rule' => $this->ruleId, 'severity' => $this->severity->value, 'message' => $this->message];
    }
}
