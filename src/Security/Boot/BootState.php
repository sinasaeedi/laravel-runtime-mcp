<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Boot;

/**
 * The boot report of this app instance, for EnsureEndpointEnabled (the second lock behind a cached
 * route).
 *
 * Bound as a container singleton, not scoped: the report comes from config at boot, not from a
 * request, and under Octane the provider boots once per worker, so a scoped instance would be empty
 * from the second request on. It holds no request data (rule 14) and no static property.
 */
final class BootState
{
    private ?BootReport $report = null;

    public function record(BootReport $report): void
    {
        $this->report = $report;
    }

    /** Null until the provider has validated (or when runtime-mcp.enabled was not true at boot). */
    public function report(): ?BootReport
    {
        return $this->report;
    }

    /** No report = closed (principle 1.4.3). */
    public function httpAllowed(): bool
    {
        return $this->report?->httpAllowed() ?? false;
    }
}
