<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tools;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Arr;
use JsonException;

/**
 * Spec 4.4 step 8, 5.8, 5.12, 01-17 Part C: every result is at most limit() bytes of envelope JSON,
 * measured exactly as AuditStage encodes it, and a cut result says what was cut.
 *
 * Over the cap, in this order: (1) drop items from the end of the main list (listKey); when not even
 * one item fits, keep the first and (2) cut the longest strings to a common length; (3) as a last
 * resort, return a prefix of the data's JSON as one string. Each step finds its size with a binary
 * search: about log2(n) encodes, never one per item.
 *
 * Reads the config on every call (rule 14); holds no state.
 */
final class OutputCap
{
    public const DEFAULT_MAX_BYTES = 100_000;

    public const MIN_MAX_BYTES = 1_000;

    /** Step 2 never cuts a string to fewer bytes than this. */
    public const MIN_STRING_BYTES = 256;

    /** Step 2 cuts only a string longer than the kept length plus this: the marker is shorter, so a cut always saves bytes. */
    public const MARKER_ROOM = 32;

    public const CUT_MARKER = '…[cut %d bytes]';

    public const TRIMMED = 'The result was larger than %d bytes; the last %d of %d %s were omitted. Narrow the request.';

    public const TRIMMED_AND_CUT = 'The result was larger than %d bytes; the last %d of %d %s were omitted, and %d bytes of long text were cut. Narrow the request.';

    public const CUT = 'The result was larger than %d bytes; %d bytes of long text were cut. Narrow the request.';

    public const FLATTENED = 'The result was too large to return as structured data; its JSON was cut after %d of %d bytes. Narrow the request.';

    public function __construct(private readonly Repository $config) {}

    /**
     * The cap in use: output.max_bytes. 01-40 adds the catalog branch here, the one place that decides
     * (spec 5.8, 5.17).
     */
    public function limit(): int
    {
        return $this->maxBytes();
    }

    /** output.max_bytes: an int or a digit string, at least 1,000; anything else gives 100,000 (doctor fails). */
    public function maxBytes(): int
    {
        return $this->configuredMaxBytes() ?? self::DEFAULT_MAX_BYTES;
    }

    public function maxBytesIsValid(): bool
    {
        return $this->configuredMaxBytes() !== null;
    }

    /** The result unchanged when it fits or cannot be encoded (AuditStage then answers NOT_ENCODABLE). */
    public function cap(ToolResult $result, string $environment): ToolResult
    {
        $max = $this->limit();
        $size = self::size($result, $environment);

        if ($size === null || $size <= $max) {
            return $result;
        }

        $fits = fn (ToolResult $candidate): bool => (self::size($candidate, $environment) ?? PHP_INT_MAX) <= $max;

        // An unavailable result has no data to cut: only its note can be too long (rule 11: it stays unavailable).
        if ($result->status === Status::Unavailable) {
            return $this->fitNote($result, null, (string) $result->note, false, $result->rowCount, $fits);
        }

        $data = $result->data;
        $list = self::listAt($result);
        $dropped = 0;

        if ($list !== null && $list !== []) {
            $total = count($list);
            $word = self::word((string) $result->listKey);
            $trimmed = fn (int $kept): ToolResult => $result->capped(
                self::withList($data, (string) $result->listKey, array_slice($list, 0, $kept)),
                self::note($result, sprintf(self::TRIMMED, $max, $total - $kept, $total, $word)),
                self::truncated($result, $total - $kept, 0),
                $result->rowCount === null ? null : $kept,
            );
            $kept = self::largest(1, $total - 1, fn (int $kept): bool => $fits($trimmed($kept)));

            if ($kept >= 1) {
                return $trimmed($kept);
            }

            // Not even one item fits: keep the first, then cut its strings (step 2), so the agent still sees one.
            $data = self::withList($data, (string) $result->listKey, [$list[0]]);
            $dropped = $total - 1;
        }

        $cut = $this->cutStrings($result, $data, $dropped, $list === null ? 0 : count($list), $max, $fits);

        return $cut ?? $this->flatten($result, $max, $fits);
    }

    /** @param  Closure(ToolResult): bool  $fits */
    private function cutStrings(ToolResult $result, mixed $data, int $dropped, int $total, int $max, Closure $fits): ?ToolResult
    {
        $longest = self::longestString($data);

        if ($longest <= self::MIN_STRING_BYTES + self::MARKER_ROOM) {
            return null;
        }

        $word = self::word((string) $result->listKey);
        $build = function (int $length) use ($result, $data, $dropped, $total, $word, $max): ToolResult {
            $bytes = 0;
            $cut = self::cutLeaves($data, $length, $bytes);
            $sentence = $dropped > 0
                ? sprintf(self::TRIMMED_AND_CUT, $max, $dropped, $total, $word, $bytes)
                : sprintf(self::CUT, $max, $bytes);

            return $result->capped(
                $cut,
                self::note($result, $sentence),
                self::truncated($result, $dropped, $bytes),
                $dropped > 0 && $result->rowCount !== null ? 1 : $result->rowCount,
            );
        };
        $length = self::largest(self::MIN_STRING_BYTES, $longest - self::MARKER_ROOM - 1, fn (int $length): bool => $fits($build($length)));

        return $length >= self::MIN_STRING_BYTES ? $build($length) : null;
    }

    /**
     * Step 3: a prefix of the data's JSON, at most max_bytes / 2 bytes (the prefix is escaped again inside
     * the envelope, so the search still checks the size). No row count: the rows sent cannot be counted
     * in a prefix.
     *
     * @param  Closure(ToolResult): bool  $fits
     */
    private function flatten(ToolResult $result, int $max, Closure $fits): ToolResult
    {
        $json = (string) json_encode($result->data, ResultEnvelope::JSON_FLAGS);
        $total = strlen($json);
        $build = function (int $length) use ($result, $json, $total): ToolResult {
            $kept = mb_strcut($json, 0, $length, 'UTF-8');

            return $result->capped(
                $kept,
                self::note($result, sprintf(self::FLATTENED, strlen($kept), $total)),
                self::truncated($result, 0, $total - strlen($kept)),
                null,
            );
        };
        $length = self::largest(0, min($total - 1, intdiv($max, 2)), fn (int $length): bool => $fits($build($length)));

        if ($length >= 0) {
            return $build($length);
        }

        // Even an empty prefix does not fit: the tool's own note is too long. Cut it too.
        return $this->fitNote($result, '', (string) $build(0)->note, self::truncated($result, 0, $total), null, $fits);
    }

    /**
     * The longest prefix of $note (plus the cut marker) that fits.
     *
     * @param  Closure(ToolResult): bool  $fits
     */
    private function fitNote(ToolResult $result, mixed $data, string $note, int|false $truncated, ?int $rowCount, Closure $fits): ToolResult
    {
        $build = function (int $length) use ($result, $data, $note, $truncated, $rowCount): ToolResult {
            $kept = mb_strcut($note, 0, $length, 'UTF-8');

            return $result->capped($data, $kept.sprintf(self::CUT_MARKER, strlen($note) - strlen($kept)), $truncated, $rowCount);
        };

        return $build(max(0, self::largest(0, strlen($note) - 1, fn (int $length): bool => $fits($build($length)))));
    }

    /**
     * The size of one copy of the envelope JSON, exactly as AuditStage encodes it; null when it cannot
     * be encoded.
     */
    private static function size(ToolResult $result, string $environment): ?int
    {
        try {
            return strlen(ResultEnvelope::fromResult($result, $environment)->toJson());
        } catch (JsonException) {
            return null;
        }
    }

    /** @return list<mixed>|null the main list, or null when the result has none */
    private static function listAt(ToolResult $result): ?array
    {
        if ($result->listKey === null) {
            return null;
        }

        $list = $result->listKey === '*' ? $result->data : (is_array($result->data) ? Arr::get($result->data, $result->listKey) : null);

        return is_array($list) && array_is_list($list) ? $list : null;
    }

    /** @param  list<mixed>  $items */
    private static function withList(mixed $data, string $listKey, array $items): mixed
    {
        if ($listKey === '*' || ! is_array($data)) {
            return $items;
        }

        Arr::set($data, $listKey, $items);

        return $data;
    }

    /** The noun of the note: the last part of the list key ("rows", "entries"), or "items". */
    private static function word(string $listKey): string
    {
        $last = substr((string) strrchr('.'.$listKey, '.'), 1);

        return $last === '' || $last === '*' ? 'items' : $last;
    }

    private static function note(ToolResult $result, string $sentence): string
    {
        return $result->note === null ? $sentence : $result->note.' '.$sentence;
    }

    /**
     * What `truncated` says after a cut. A result the tool already marked partial keeps its own count,
     * plus the items dropped from the list; when the tool could not count (false), it stays false
     * (principle 1.4.8). Otherwise: the items dropped, or else the bytes cut.
     */
    private static function truncated(ToolResult $result, int $dropped, int $bytes): int|false
    {
        if ($result->status === Status::Partial) {
            return $result->truncated === false ? false : $result->truncated + $dropped;
        }

        return $dropped > 0 ? $dropped : $bytes;
    }

    private static function longestString(mixed $value): int
    {
        if (is_string($value)) {
            return strlen($value);
        }

        $longest = 0;

        foreach (is_array($value) ? $value : [] as $item) {
            $longest = max($longest, self::longestString($item));
        }

        return $longest;
    }

    /** Every string longer than $length + MARKER_ROOM bytes becomes its first $length bytes (UTF-8 safe) and the marker. */
    private static function cutLeaves(mixed $value, int $length, int &$bytes): mixed
    {
        if (is_string($value) && strlen($value) > $length + self::MARKER_ROOM) {
            $kept = mb_strcut($value, 0, $length, 'UTF-8');
            $removed = strlen($value) - strlen($kept);
            $bytes += $removed;

            return $kept.sprintf(self::CUT_MARKER, $removed);
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::cutLeaves($item, $length, $bytes);
            }
        }

        return $value;
    }

    /**
     * The largest $x in [$low, $high] for which $fits($x) holds, or $low - 1. $fits holds up to some
     * point and fails after it.
     *
     * @param  Closure(int): bool  $fits
     */
    private static function largest(int $low, int $high, Closure $fits): int
    {
        $best = $low - 1;

        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);

            if ($fits($middle)) {
                $best = $middle;
                $low = $middle + 1;
            } else {
                $high = $middle - 1;
            }
        }

        return $best;
    }

    private function configuredMaxBytes(): ?int
    {
        $value = $this->config->get('runtime-mcp.output.max_bytes');

        // (int) of a longer digit string gives PHP_INT_MAX: refused, as MaxRowsSetting does.
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        return is_int($value) && $value >= self::MIN_MAX_BYTES && $value < PHP_INT_MAX ? $value : null;
    }
}
