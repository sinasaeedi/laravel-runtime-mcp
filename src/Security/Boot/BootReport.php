<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Boot;

/**
 * The result of one BootValidator::validate(): every issue, plus what each transport needs on top
 * of "no error".
 */
final readonly class BootReport
{
    /**
     * @param  list<BootIssue>  $issues  in rule order
     * @param  bool  $httpReady  the HTTP endpoint has credentials to check (01-10: no api_key driver without keys)
     * @param  bool  $stdioReady  auth.stdio_principal is a valid name (spec 5.3)
     */
    public function __construct(
        public array $issues,
        private bool $httpReady,
        private bool $stdioReady,
    ) {}

    /** @return list<BootIssue> */
    public function errors(): array
    {
        return $this->withSeverity(Severity::Error);
    }

    /** @return list<BootIssue> */
    public function warnings(): array
    {
        return $this->withSeverity(Severity::Warning);
    }

    /** No error. Warnings do not count. */
    public function passed(): bool
    {
        return $this->errors() === [];
    }

    public function httpAllowed(): bool
    {
        return $this->passed() && $this->httpReady;
    }

    public function stdioAllowed(): bool
    {
        return $this->passed() && $this->stdioReady;
    }

    /** @return list<BootIssue> */
    private function withSeverity(Severity $severity): array
    {
        return array_values(array_filter(
            $this->issues,
            fn (BootIssue $issue): bool => $issue->severity === $severity,
        ));
    }
}
