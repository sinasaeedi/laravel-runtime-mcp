<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Audit\Events;

use SinaSaeedi\RuntimeMcp\Audit\AuditEntry;

/**
 * Spec 5.7: a tool call that a stage denied (warning).
 *
 * Dispatched after the entry is written, with the stored entry (params redacted and capped).
 * A plain event: the app decides if a listener queues (spec 5.7, "forward them").
 */
final class ToolDenied
{
    public function __construct(public readonly AuditEntry $entry) {}
}
