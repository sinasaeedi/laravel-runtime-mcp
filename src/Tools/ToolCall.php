<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tools;

use SinaSaeedi\RuntimeMcp\Principal;
use SinaSaeedi\RuntimeMcp\Tools\Contracts\RuntimeTool;

/**
 * One tool call on its way through the guard pipeline. One object per call, never shared or kept
 * (rule 14). Stage 1 sets the principal; the audit stage (outermost) reads it on the way out.
 */
final class ToolCall
{
    private ?Principal $principal = null;

    /**
     * @param  array<array-key, mixed>  $arguments  the raw arguments, before validation
     * @param  array{name: string, version: ?string}|null  $client  from ClientInfo::from()
     * @param  int  $startedAt  hrtime(true) when the call began
     */
    public function __construct(
        public readonly RuntimeTool $tool,
        public readonly array $arguments,
        public readonly ?array $client,
        public readonly int $startedAt,
    ) {}

    public function setPrincipal(Principal $principal): void
    {
        $this->principal = $principal;
    }

    public function principal(): ?Principal
    {
        return $this->principal;
    }
}
