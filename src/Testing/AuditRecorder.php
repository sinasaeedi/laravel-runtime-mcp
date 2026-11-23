<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Testing;

use Illuminate\Contracts\Events\Dispatcher;
use SinaSaeedi\RuntimeMcp\Audit\AuditEntry;
use SinaSaeedi\RuntimeMcp\Audit\Events\ToolCalled;
use SinaSaeedi\RuntimeMcp\Audit\Events\ToolDenied;
use SinaSaeedi\RuntimeMcp\Audit\Events\ToolFailed;

/**
 * The test kit's view of the audit (01-39). It listens to the three events that AuditLogger dispatches after it has
 * written an entry: 01-05 has no other test seam (Sina, 2026-10-16). A tools/call entry and a prompts/get entry
 * (01-36) both come through them; AuditEntry::$kind tells them apart.
 *
 * One recorder per RuntimeMcp::test() chain, held by that chain only (no static state, rule 14).
 */
final class AuditRecorder
{
    /** @var list<AuditEntry> */
    private array $entries = [];

    public static function listen(Dispatcher $events): self
    {
        $recorder = new self;
        $events->listen([ToolCalled::class, ToolDenied::class, ToolFailed::class], $recorder->record(...));

        return $recorder;
    }

    public function record(ToolCalled|ToolDenied|ToolFailed $event): void
    {
        $this->entries[] = $event->entry;
    }

    /** The number of entries so far: a mark for since(). */
    public function count(): int
    {
        return count($this->entries);
    }

    /** @return list<AuditEntry> the entries recorded after the mark */
    public function since(int $mark): array
    {
        return array_slice($this->entries, $mark);
    }
}
