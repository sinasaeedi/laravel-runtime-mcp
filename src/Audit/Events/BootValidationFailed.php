<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Audit\Events;

use SinaSaeedi\RuntimeMcp\Security\Boot\BootIssue;

/**
 * Spec 5.11: boot validation failed in a strict environment, so the endpoint is off. Dispatched at
 * most once per 10 minutes per set of failing rules (BootThrottle). Not an audit entry: no request,
 * no principal (01-05).
 *
 * A plain event: the app decides if a listener queues or pages someone.
 */
final class BootValidationFailed
{
    /** @param  list<BootIssue>  $issues  every issue of the report, errors and warnings, in rule order */
    public function __construct(
        public readonly string $environment,
        public readonly array $issues,
    ) {}
}
