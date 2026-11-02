<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tools;

use Illuminate\Support\Arr;
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
    /** A list key: '*' (data is the list), or a dot path of names (01-17 Part C). */
    public const LIST_KEY_PATTERN = '/^(\*|[A-Za-z0-9_]+(\.[A-Za-z0-9_]+)*)$/D';

    /**
     * @param  int|false  $truncated  false, or the number of omitted rows or bytes
     * @param  string|null  $listKey  where the main list is, so the output cap trims items, not text (01-17)
     */
    private function __construct(
        public Status $status,
        public mixed $data,
        public ?string $note,
        public int|false $truncated,
        public ?int $rowCount,
        public ?string $listKey = null,
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

    /** The same result with other data and note (RunTool, RedactStage). The rules apply again; the list key stays. */
    public function withContent(mixed $data, ?string $note): self
    {
        return new self($this->status, $data, $note, $this->truncated, $this->rowCount, $this->listKey);
    }

    /**
     * Marks the main list of the data: 'rows', 'entries', a dot path, or '*' when the data is the list.
     * The output cap (01-17) then drops items from its end instead of cutting text. A key that does not
     * lead to a list now is a programming error.
     */
    public function withListKey(string $key): self
    {
        if (preg_match(self::LIST_KEY_PATTERN, $key) !== 1) {
            throw new LogicException("[{$key}] is not a list key: use '*' or a dot path of names.");
        }

        // Arr::get() reads a literal dotted key first, but the cap writes the list back with Arr::set(), which always nests.
        if (str_contains($key, '.') && is_array($this->data) && array_key_exists($key, $this->data)) {
            throw new LogicException("The list key [{$key}] is one key with a dot in the data; a list key is a dot path of nested keys.");
        }

        $list = $key === '*' ? $this->data : (is_array($this->data) ? Arr::get($this->data, $key) : null);

        if (! is_array($list) || ! array_is_list($list)) {
            throw new LogicException("The list key [{$key}] does not lead to a list in the data.");
        }

        return new self($this->status, $this->data, $this->note, $this->truncated, $this->rowCount, $key);
    }

    /**
     * The result cut by the output cap (01-17), the only stage that calls it. A cut result is partial
     * (rule 11: never ok or empty); an unavailable one stays unavailable, with truncated false. The list
     * key is dropped: nothing reads it after the cap.
     */
    public function capped(mixed $data, string $note, int|false $truncated, ?int $rowCount): self
    {
        $status = $this->status === Status::Unavailable ? Status::Unavailable : Status::Partial;

        return new self($status, $data, $note, $truncated, $rowCount);
    }
}
