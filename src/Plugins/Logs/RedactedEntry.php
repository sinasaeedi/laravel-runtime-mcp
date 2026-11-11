<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins\Logs;

use SinaSaeedi\RuntimeMcp\Security\Redaction\Redactor;
use SinaSaeedi\RuntimeMcp\Support\Timestamp;
use SinaSaeedi\RuntimeMcp\Tools\ResultEnvelope;

/**
 * 01-25 Part C: one entry as the agent may see it. Redacted whole, then cut (RunTool::cut()'s rule, 01-24 FS11): a
 * secret cut in half matches no pattern. `contains` is matched on this redacted text, never on the raw line: a match
 * on the raw line would let an agent read a redacted key one character at a time from match and no match.
 *
 * Stage 7 (RedactStage) redacts the result again; the markers match no pattern, so that changes nothing.
 */
final readonly class RedactedEntry
{
    public const MAX_CONTEXT_BYTES = 2_000;

    /** As 01-17's output cap: the bytes cut, after the kept bytes. */
    public const CUT_MARKER = '…[cut %d bytes]';

    /** @param  string|array<array-key, mixed>|null  $context  redacted, not cut */
    private function __construct(
        public LogEntry $entry,
        public string $message,
        public string|array|null $context,
    ) {}

    public static function of(LogEntry $entry, Redactor $redactor): self
    {
        $context = $entry->context;

        if (is_string($context)) {
            $context = $redactor->redactString($context);
        } elseif (is_array($context)) {
            $values = $redactor->redact($context);
            assert(is_array($values));
            $json = self::text($values);
            $whole = $redactor->redactString($json);
            // redact() keeps keys (01-04), but a JSON context's keys are app data: when its whole JSON redacts further
            // (a secret used as a key), the context is that redacted JSON, as in the line format.
            $context = $whole === $json ? $values : $whole;
        }

        return new self($entry, $redactor->redactString($entry->message), $context);
    }

    /** Case-insensitive, in the redacted message or the redacted context (an array: its JSON). */
    public function contains(string $needle): bool
    {
        return mb_stripos($this->message, $needle) !== false
            || mb_stripos(self::text($this->context), $needle) !== false;
    }

    /**
     * The entry in the result: a string context over 2,000 bytes is cut; an array context whose JSON is over 2,000
     * bytes becomes that JSON, cut.
     *
     * @return array{time: string, level: string, env: string, channel: string, file: string, message: string, context: string|array<array-key, mixed>|null}
     */
    public function toArray(string $channel, string $file): array
    {
        $context = $this->context;

        if (is_array($context) && strlen(self::text($context)) > self::MAX_CONTEXT_BYTES) {
            $context = self::text($context);
        }

        if (is_string($context) && strlen($context) > self::MAX_CONTEXT_BYTES) {
            $kept = mb_strcut($context, 0, self::MAX_CONTEXT_BYTES, 'UTF-8');
            $context = $kept.sprintf(self::CUT_MARKER, strlen($context) - strlen($kept));
        }

        return [
            'time' => (string) Timestamp::iso($this->entry->time),
            'level' => $this->entry->level,
            'env' => $this->entry->env,
            'channel' => $channel,
            'file' => $file,
            'message' => $this->message,
            'context' => $context,
        ];
    }

    /** @param  string|array<array-key, mixed>|null  $context */
    private static function text(string|array|null $context): string
    {
        return is_array($context) ? (string) json_encode($context, ResultEnvelope::JSON_FLAGS) : (string) $context;
    }
}
