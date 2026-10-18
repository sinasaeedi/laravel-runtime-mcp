<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tools;

use LogicException;

/**
 * What a tool returns (spec 4.4). The named constructors enforce the envelope rules, so a tool
 * cannot build a silent empty result (rule 11, principle 1.4.7) or invent a count (principle 1.4.8).
 *
 * A broken rule is a programming error: LogicException. Inside a call, RunTool turns it into a
 * failed, audited call.
 */
final readonly class ToolResult
{
    /** @param  int|false  $truncated  false, or the number of omitted rows or bytes */
    private function __construct(
        public Status $status,
        public mixed $data,
        public ?string $note,
        public int|false $truncated,
        public ?int $rowCount,
    ) {
        if ($status === Status::Ok && $note !== null) {
            throw new LogicException('An ok result has no note.');
        }

        if ($status !== Status::Ok && ($note === null || trim($note) === '')) {
            throw new LogicException("A result with status [{$status->value}] needs a note that says what was searched or why the tool could not look.");
        }

        if ($truncated !== false && ($status !== Status::Partial || $truncated < 1)) {
            throw new LogicException('Truncated is false, or a count of 1 or more on a partial result.');
        }

        if ($rowCount !== null && $rowCount < 0) {
            throw new LogicException('A row count is 0 or more.');
        }
    }

    /** @param  array<array-key, mixed>  $data */
    public static function ok(array $data, ?int $rowCount = null): self
    {
        return new self(Status::Ok, $data, null, false, $rowCount);
    }

    /**
     * Nothing matched. The note says what was searched.
     *
     * @param  array<array-key, mixed>  $data  for example the columns of an empty query result
     */
    public static function empty(string $note, array $data = []): self
    {
        return new self(Status::Empty, $data, $note, false, 0);
    }

    /**
     * Part of the result. $truncated counts the omitted rows or bytes; keep it false when the
     * amount is not known (db_query fetches max_rows + 1, 01-15), and say "more exist" in the note.
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function partial(array $data, string $note, int|false $truncated = false, ?int $rowCount = null): self
    {
        return new self(Status::Partial, $data, $note, $truncated, $rowCount);
    }

    /** The tool could not look. The note says why. Never use empty() for this (rule 11). */
    public static function unavailable(string $note): self
    {
        return new self(Status::Unavailable, null, $note, false, null);
    }

    /** The same result with other data and note (RedactStage; 01-17 cuts it). The rules apply again. */
    public function withContent(mixed $data, ?string $note): self
    {
        return new self($this->status, $data, $note, $this->truncated, $this->rowCount);
    }
}
