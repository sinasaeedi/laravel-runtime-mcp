<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Redaction;

use BackedEnum;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Stringable;
use UnitEnum;

/**
 * Spec 5.6: the one redaction engine. Every tool output and every audit params entry pass through it.
 *
 * It replaces known secret formats in text with a typed marker. It is not a column mask and not a
 * PII filter (rule 13). The built-in patterns are always on (rule 12); extra_patterns can only add.
 *
 * Bound as scoped (rule 14). It reads extra_patterns once, in the constructor, per scope.
 */
final class Redactor
{
    /** Replaces a failed job's serialized data (spec 5.6, 01-24). redact() never produces it. */
    public const PAYLOAD = '[redacted:payload]';

    /** A whole string that the regex engine could not scan (principle 1.4.3). */
    public const UNSCANNABLE = '[redacted:unscannable]';

    /** A value that is not plain data, or nested deeper than MAX_DEPTH. */
    public const UNSUPPORTED = '[redacted:unsupported_value]';

    /** The marker type of a user pattern given as a plain string. */
    public const CUSTOM_TYPE = 'custom';

    /** The top value is depth 1. A value deeper than this becomes UNSUPPORTED. */
    public const MAX_DEPTH = 64;

    private const TYPE_RULE = '/^[a-z][a-z0-9_]{0,31}$/';

    /** @var list<array{string, string}> [regex, marker], built-in first */
    private readonly array $patterns;

    /** @throws InvalidRedactionPattern for a bad entry in runtime-mcp.redaction.extra_patterns */
    public function __construct(Repository $config)
    {
        $patterns = [];

        foreach (Patterns::BUILT_IN as $type => $regex) {
            $patterns[] = [$regex, self::marker($type)];
        }

        foreach (self::extraPatterns($config) as [$regex, $type]) {
            $patterns[] = [$regex, self::marker($type)];
        }

        $this->patterns = $patterns;
    }

    /** Redacts every string inside $value, recursively. Keys are kept. Other scalars and null are kept. */
    public function redact(mixed $value): mixed
    {
        return $this->redactValue($value, 1);
    }

    /** Redacts one string. A PCRE failure gives UNSCANNABLE for the whole string. */
    public function redactString(string $text): string
    {
        foreach ($this->patterns as [$regex, $marker]) {
            $result = preg_replace($regex, $marker, $text);

            if ($result === null || preg_last_error() !== PREG_NO_ERROR) {
                return self::UNSCANNABLE;
            }

            $text = $result;
        }

        return $text;
    }

    /** @return list<string> the eleven built-in marker types, in pattern order */
    public function markerTypes(): array
    {
        return array_keys(Patterns::BUILT_IN);
    }

    /** @return list<string> the other marker types the package writes */
    public function extraMarkerTypes(): array
    {
        return [self::CUSTOM_TYPE, 'unscannable', 'unsupported_value', 'payload'];
    }

    private function redactValue(mixed $value, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            return self::UNSUPPORTED;
        }

        if (is_string($value)) {
            return $this->redactString($value);
        }

        if ($value === null || is_int($value) || is_float($value) || is_bool($value)) {
            return $value;
        }

        if (is_array($value)) {
            $redacted = [];

            foreach ($value as $key => $item) {
                $redacted[$key] = $this->redactValue($item, $depth + 1);
            }

            return $redacted;
        }

        // Enums first: an enum may also implement JsonSerializable.
        if ($value instanceof BackedEnum) {
            return $this->redactValue($value->value, $depth);
        }

        if ($value instanceof UnitEnum) {
            return $this->redactString($value->name);
        }

        if ($value instanceof JsonSerializable) {
            return $this->redactValue($value->jsonSerialize(), $depth + 1);
        }

        if ($value instanceof Arrayable) {
            return $this->redactValue($value->toArray(), $depth + 1);
        }

        if ($value instanceof Stringable) {
            return $this->redactString((string) $value);
        }

        // A resource, a closure, any other object: fail closed, never print_r().
        return self::UNSUPPORTED;
    }

    /**
     * Reads and checks runtime-mcp.redaction.extra_patterns. A value that is not an array means
     * "no extra patterns": it can never remove a built-in one.
     *
     * @return list<array{string, string}> [regex, type]
     */
    private static function extraPatterns(Repository $config): array
    {
        $entries = $config->get('runtime-mcp.redaction.extra_patterns');

        if (! is_array($entries)) {
            return [];
        }

        $patterns = [];

        foreach ($entries as $key => $entry) {
            if (is_string($entry)) {
                [$regex, $type] = [$entry, self::CUSTOM_TYPE];
            } elseif (
                is_array($entry)
                && count($entry) === 2 // exactly these two keys, so a typo such as 'regex' is refused
                && isset($entry['pattern'], $entry['type'])
                && is_string($entry['pattern'])
                && is_string($entry['type'])
            ) {
                [$regex, $type] = [$entry['pattern'], $entry['type']];
            } else {
                throw new InvalidRedactionPattern($key, InvalidRedactionPattern::NOT_AN_ENTRY);
            }

            if (preg_match(self::TYPE_RULE, $type) !== 1) {
                throw new InvalidRedactionPattern($key, InvalidRedactionPattern::BAD_TYPE);
            }

            // @: a bad regex emits a warning; false is the answer we need.
            if (@preg_match($regex, '') === false) {
                throw new InvalidRedactionPattern($key, InvalidRedactionPattern::DOES_NOT_COMPILE);
            }

            $patterns[] = [$regex, $type];
        }

        return $patterns;
    }

    private static function marker(string $type): string
    {
        return "[redacted:{$type}]";
    }
}
