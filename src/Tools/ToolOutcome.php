<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tools;

use SinaSaeedi\RuntimeMcp\Audit\Reason;
use Throwable;

/**
 * What one pass through the guard pipeline gives. A denial or a failure carries an unavailable
 * result whose note says why, so the agent always gets an envelope (spec 4.4).
 *
 * The audit stage adds the encoded envelope (withEnvelope()); McpToolAdapter sends exactly that.
 */
final readonly class ToolOutcome
{
    /** @param  array<string, mixed>|null  $envelope */
    private function __construct(
        public bool $allowed,
        public ?Reason $reason,
        public ToolResult $result,
        public ?string $errorClass,
        public bool $isError,
        public ?array $envelope = null,
    ) {}

    /** The tool ran and returned a result. Its own unavailable result is not an error. */
    public static function success(ToolResult $result): self
    {
        return new self(true, null, $result, null, false);
    }

    /** A guard said no. Audited as denied, with the reason. */
    public static function denied(Reason $reason, string $note): self
    {
        return new self(false, $reason, ToolResult::unavailable($note), null, true);
    }

    /** The call was allowed but did not give a result. Audited as an error, with the class. */
    public static function failed(Throwable $error, string $note): self
    {
        return new self(true, null, ToolResult::unavailable($note), $error::class, true);
    }

    /** The same outcome with another result (RedactStage; 01-17 caps it). */
    public function withResult(ToolResult $result): self
    {
        return new self($this->allowed, $this->reason, $result, $this->errorClass, $this->isError);
    }

    /** @param  array<string, mixed>  $envelope  the decoded, clean envelope (AuditStage) */
    public function withEnvelope(array $envelope): self
    {
        return new self($this->allowed, $this->reason, $this->result, $this->errorClass, $this->isError, $envelope);
    }
}
