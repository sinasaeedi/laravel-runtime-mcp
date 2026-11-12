<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins\Logs;

/**
 * Spec 6.10, 01-26 Part A: the cause of an error, as a pattern of its message with numbers, UUIDs and IDs replaced,
 * and a short hash of the level and the pattern. Takes the REDACTED message: normalising a raw secret would break
 * the redaction pattern that stage 7 runs (sk_live_4eC… becomes sk_live_{n}eC…), so a part of it could reach the
 * pattern.
 *
 * Every ID rule is bounded by "not a letter or a digit" on both sides, never by \b: \b does not separate at "_", so
 * user_7f3a9c21d4 would not match and each ID would be its own group.
 */
final class ErrorFingerprint
{
    /** The pattern is cut to this many characters before it is hashed. */
    public const MAX_PATTERN = 500;

    /** The rules, in this order: a UUID or a hex ID first, or the number rule would split it into {n} parts. */
    private const RULES = [
        '/(?<![0-9a-z])[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}(?![0-9a-z])/i' => '{uuid}',
        // At least one digit and one letter: a pure number is {n} at every length, a word like "deadbeef" stays.
        '/(?<![0-9a-z])(?=[0-9a-f]*\d)(?=[0-9a-f]*[a-f])[0-9a-f]{8,}(?![0-9a-z])/i' => '{hex}',
        // ULIDs (HasUlids), Str::random() tokens, provider IDs such as ch_3MmlLrLkdIwHu7ix0snN0B15.
        '/(?<![0-9a-z])(?=[0-9a-z]*\d)(?=[0-9a-z]*[a-z])[0-9a-z]{16,}(?![0-9a-z])/i' => '{id}',
        // A decimal is one number, so "2s" and "1.5s" are one cause.
        '/\d+(?:\.\d+)?/' => '{n}',
    ];

    public static function pattern(string $message): string
    {
        // A log line can hold invalid UTF-8: scrubbed first, so the /u rule below never fails and the pattern is valid.
        $text = (string) preg_replace(array_keys(self::RULES), array_values(self::RULES), mb_scrub($message, 'UTF-8'));

        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $text)), 0, self::MAX_PATTERN);
    }

    /** The first 12 hex characters of sha1("<level>|<pattern>"): the same message at error and at critical is two groups. */
    public static function of(string $level, string $pattern): string
    {
        return substr(sha1($level.'|'.$pattern), 0, 12);
    }
}
