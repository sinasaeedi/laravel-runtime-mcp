<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Testing;

use Closure;
use PHPUnit\Framework\Assert;
use SinaSaeedi\RuntimeMcp\Audit\AuditEntry;
use SinaSaeedi\RuntimeMcp\Mcp\EnvelopeSchema;
use SinaSaeedi\RuntimeMcp\Mcp\McpToolAdapter;
use SinaSaeedi\RuntimeMcp\Tools\ResultEnvelope;

/**
 * The answer to one test-kit tools/call (01-39), with the audit entries that the call wrote.
 *
 * Every assertion fails through PHPUnit's Assert (an AssertionFailedError, or its subclass ExpectationFailedException)
 * and makes one real assertion when it passes, so PHPUnit never marks a kit test "risky". A failure names the tool and,
 * when there is an envelope, its status and note.
 */
final class RuntimeMcpResult
{
    /** %1$s is the tool, %2$s the JSON-RPC error message. */
    public const NO_ENVELOPE = 'The call of [%1$s] returned no envelope: %2$s';

    /** %1$s is the tool, %2$s the expected status, %3$s the status, %4$s the note. */
    public const WRONG_STATUS = 'The call of [%1$s] has status [%3$s], not [%2$s]. Note: %4$s';

    /** %s is the tool. */
    public const IS_ERROR = 'The call of [%s] is an error (isError).';

    /** %1$s is the tool, %2$s the text, %3$s the note. */
    public const NO_NOTE = 'The note of [%1$s] does not contain [%2$s]. Note: %3$s';

    /** %s is the tool. */
    public const WRONG_DATA = 'The data of [%s] is not the expected data.';

    /** %s is the tool. */
    public const DATA_CLOSURE = 'The data of [%s] does not pass the given closure (it must return true).';

    /** %1$s is the tool, %2$s the expected value, %3$s the value. */
    public const WRONG_TRUNCATED = 'The call of [%1$s] has truncated %3$s, not %2$s.';

    /** %1$s is the tool, %2$s the expected count, %3$s the count. */
    public const AUDIT_COUNT = 'The call of [%1$s] wrote %3$d tool_call audit entries, not %2$d.';

    /** Added to AUDIT_COUNT when no entry was recorded and Event::fake() is on. */
    public const EVENTS_FAKED = ' Event::fake() hides the audit events from the kit. Fake only your own events: Event::fake([YourEvent::class]).';

    /** %1$s is the tool, %2$s the expected decision, %3$s the decision, %4$s the reason. */
    public const WRONG_DECISION = 'The call of [%1$s] was audited as [%3$s] (reason %4$s), not [%2$s].';

    /** %1$s is the tool, %2$s the expected reason, %3$s the reason. */
    public const WRONG_REASON = 'The call of [%1$s] was denied with the reason [%3$s], not [%2$s].';

    /** %s is the tool. */
    public const NOT_AN_ERROR = 'The denied call of [%s] is not an error.';

    /** %1$s is the tool, %2$s the outcome. */
    public const NOT_FAILED = 'The call of [%1$s] did not fail: its outcome is [%2$s].';

    /** %1$s is the tool, %2$s the expected class, %3$s the class. */
    public const WRONG_ERROR_CLASS = 'The call of [%1$s] failed with [%3$s], not [%2$s].';

    /** %1$s is the tool, %2$s the violations. */
    public const SCHEMA = 'The envelope of [%1$s] does not match the outputSchema: %2$s';

    public const NO_PROGRESS = 'Call withProgress() before call(): without a progressToken a tool sends no progress.';

    /** %1$s is the tool, %2$s the expected count, %3$s the count. */
    public const PROGRESS_COUNT = 'The call of [%1$s] sent %3$d progress notifications, not %2$d.';

    /**
     * @param  array<array-key, mixed>  $response  the JSON-RPC response
     * @param  list<array<array-key, mixed>>  $notifications
     * @param  list<AuditEntry>  $entries  the audit entries written while the call ran
     */
    public function __construct(
        private readonly string $tool,
        private readonly array $response,
        private readonly array $notifications,
        private readonly array $entries,
        private readonly bool $progress,
        private readonly bool $eventsFaked,
    ) {}

    /**
     * The envelope (structuredContent): environment, status, note, truncated, data (spec 4.4).
     *
     * @return array<array-key, mixed>
     */
    public function envelope(): array
    {
        $envelope = data_get($this->response, 'result.structuredContent');

        if (! is_array($envelope)) {
            $error = data_get($this->response, 'error.message');
            Assert::fail(sprintf(self::NO_ENVELOPE, $this->tool, is_string($error) ? $error : 'no structuredContent.'));
        }

        return $envelope;
    }

    public function assertStatus(string $status): self
    {
        $envelope = $this->envelope();
        $actual = $envelope['status'] ?? null;

        Assert::assertSame($status, $actual, sprintf(self::WRONG_STATUS, $this->tool, $status, self::text($actual), self::text($envelope['note'] ?? null)));

        return $this;
    }

    /** Status ok, and the result is not an error. */
    public function assertOk(): self
    {
        $this->assertStatus('ok');
        Assert::assertFalse($this->isError(), sprintf(self::IS_ERROR, $this->tool));

        return $this;
    }

    public function assertNote(string $contains): self
    {
        $note = $this->envelope()['note'] ?? null;

        Assert::assertTrue(is_string($note) && str_contains($note, $contains), sprintf(self::NO_NOTE, $this->tool, $contains, self::text($note)));

        return $this;
    }

    /**
     * An array: exactly the same data (assertSame after a JSON round trip of $expected with the envelope's own flags, as
     * laravel/mcp's assertStructuredContent() does). A closure: called with the data, it must return true (as
     * assertStructuredContentPath()). The data is null in an unavailable result, so a closure typed `array $data` throws
     * a TypeError there: check the status first.
     *
     * @param  array<array-key, mixed>|Closure  $expected
     */
    public function assertData(array|Closure $expected): self
    {
        $data = $this->envelope()['data'] ?? null;

        if ($expected instanceof Closure) {
            Assert::assertTrue($expected($data) === true, sprintf(self::DATA_CLOSURE, $this->tool));

            return $this;
        }

        Assert::assertSame(json_decode((string) json_encode($expected, ResultEnvelope::JSON_FLAGS), true), $data, sprintf(self::WRONG_DATA, $this->tool));

        return $this;
    }

    /** false, or the number of omitted rows or bytes (spec 4.4). */
    public function assertTruncated(int|false $truncated): self
    {
        $actual = $this->envelope()['truncated'] ?? null;

        Assert::assertSame($truncated, $actual, sprintf(self::WRONG_TRUNCATED, $this->tool, self::text($truncated), self::text($actual)));

        return $this;
    }

    /**
     * The call was denied (with this reason, when given: no_principal, no_gate, gate_denied, not_available, unknown_tool,
     * rate_limited, …), and the answer is an error: isError, or a JSON-RPC error for an unknown tool.
     */
    public function assertDenied(?string $reason = null): self
    {
        $entry = $this->entry();

        Assert::assertSame(AuditEntry::DECISION_DENIED, $entry->decision, sprintf(self::WRONG_DECISION, $this->tool, AuditEntry::DECISION_DENIED, $entry->decision, self::text($entry->reason)));

        if ($reason !== null) {
            Assert::assertSame($reason, $entry->reason, sprintf(self::WRONG_REASON, $this->tool, $reason, self::text($entry->reason)));
        }

        Assert::assertTrue($this->isError() || isset($this->response['error']), sprintf(self::NOT_AN_ERROR, $this->tool));

        return $this;
    }

    /**
     * The call was allowed, but the tool did not give a result (an exception, refused input): outcome error, with this
     * exception class (a full class name) when given.
     */
    public function assertFailed(?string $errorClass = null): self
    {
        $entry = $this->entry();

        Assert::assertSame(AuditEntry::DECISION_ALLOWED, $entry->decision, sprintf(self::WRONG_DECISION, $this->tool, AuditEntry::DECISION_ALLOWED, $entry->decision, self::text($entry->reason)));
        Assert::assertSame(AuditEntry::OUTCOME_ERROR, $entry->outcome, sprintf(self::NOT_FAILED, $this->tool, self::text($entry->outcome)));

        if ($errorClass !== null) {
            Assert::assertSame(ltrim($errorClass, '\\'), $entry->errorClass, sprintf(self::WRONG_ERROR_CLASS, $this->tool, $errorClass, self::text($entry->errorClass)));
        }

        return $this;
    }

    /** The envelope matches the outputSchema that every tool declares (spec 4.4, 01-08). */
    public function assertMatchesOutputSchema(): self
    {
        $violations = JsonSchemaSubset::violations($this->envelope(), EnvelopeSchema::toArray());

        Assert::assertSame([], $violations, sprintf(self::SCHEMA, $this->tool, implode('; ', $violations)));

        return $this;
    }

    /** Exactly one tool_call audit entry for this call, with this decision (allowed, denied) when given. */
    public function assertAudited(?string $decision = null): self
    {
        $entry = $this->entry();

        if ($decision !== null) {
            Assert::assertSame($decision, $entry->decision, sprintf(self::WRONG_DECISION, $this->tool, $decision, $entry->decision, self::text($entry->reason)));
        }

        return $this;
    }

    /** Exactly $count progress notifications came before the result (withProgress() first). */
    public function assertProgress(int $count): self
    {
        if (! $this->progress) {
            Assert::fail(self::NO_PROGRESS);
        }

        $sent = count(array_filter($this->notifications, fn (array $message): bool => ($message['method'] ?? null) === McpToolAdapter::PROGRESS));

        Assert::assertSame($count, $sent, sprintf(self::PROGRESS_COUNT, $this->tool, $count, $sent));

        return $this;
    }

    /** The one tool_call entry of this call; fails for none or more than one. */
    private function entry(): AuditEntry
    {
        $entries = array_values(array_filter($this->entries, fn (AuditEntry $entry): bool => $entry->kind === AuditEntry::KIND_TOOL_CALL));
        $message = sprintf(self::AUDIT_COUNT, $this->tool, 1, count($entries)).($entries === [] && $this->eventsFaked ? self::EVENTS_FAKED : '');

        Assert::assertCount(1, $entries, $message);

        return $entries[0];
    }

    private function isError(): bool
    {
        return data_get($this->response, 'result.isError') === true;
    }

    private static function text(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            $value === false => 'false',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value),
        };
    }
}
